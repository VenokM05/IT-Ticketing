<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketProgressReport;
use App\Models\TicketProgressUpdate;
use App\Models\TicketStatusHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes ticket creation, status-transition validation, history recording
 * and initial formal report generation (PRD §6.3 TicketService).
 */
class TicketService
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly NotificationService $notifications,
        private readonly WhatsAppOutboundService $whatsapp,
    ) {}

    /**
     * Create a new ticket from validated intake data (PRD FR-02).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createTicket(array $attributes, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($attributes, $actor) {
            // The company is inherited from the requester's enrollment (never typed
            // into the chat / form), so intake channels cannot mislabel it.
            $companyId = $attributes['company_id'] ?? (isset($attributes['employee_id'])
                ? Employee::query()->whereKey($attributes['employee_id'])->value('company_id')
                : null);

            $status = $attributes['status'] ?? TicketStatus::New;
            $assigneeId = $attributes['assigned_to'] ?? null;

            // Auto-assign: when no technician was chosen explicitly, hand the ticket
            // to the company's least-loaded technician so it skips the unassigned
            // New queue (config tickets.auto_assign). Calls that already name an
            // assignee, or companies with no technician, are left untouched.
            $autoAssignee = null;

            if ($assigneeId === null && $companyId !== null && config('tickets.auto_assign')) {
                $categoryKey = isset($attributes['category'])
                    ? ($attributes['category'] instanceof \BackedEnum ? $attributes['category']->value : $attributes['category'])
                    : null;

                $autoAssignee = $this->resolveAutoAssignee((int) $companyId, $categoryKey);

                if ($autoAssignee !== null) {
                    $assigneeId = $autoAssignee->id;

                    if ($status === TicketStatus::New) {
                        $status = TicketStatus::Assigned;
                    }
                }
            }

            $ticket = Ticket::create([
                ...$attributes,
                'company_id' => $companyId,
                'assigned_to' => $assigneeId,
                'ticket_number' => $this->numbers->next(),
                'status' => $status,
            ]);

            $this->applySla($ticket);

            $this->recordHistory(
                $ticket,
                null,
                $ticket->status,
                $actor,
                $autoAssignee ? 'Ticket created and auto-assigned to '.$autoAssignee->name : 'Ticket created',
            );
            $this->notifications->ticketCreated($ticket);

            if ($autoAssignee !== null) {
                $ticket->load('assignee');
                $this->notifications->ticketAssigned($ticket);
            }

            return $ticket;
        });
    }

    /**
     * Stamp (or refresh) the SLA response/resolve deadlines for a ticket from its
     * priority and creation instant. Tickets whose priority has no configured
     * window keep null deadlines. Called on intake and whenever priority changes.
     */
    public function applySla(Ticket $ticket): Ticket
    {
        $window = config('tickets.sla')[$ticket->priority?->value] ?? null;

        $base = $ticket->created_at ?? now();

        $ticket->response_due_at = $window === null
            ? null
            : $base->copy()->addHours($window['response']);
        $ticket->resolve_due_at = $window === null
            ? null
            : $base->copy()->addHours($window['resolve']);
        $ticket->save();

        return $ticket;
    }

    /**
     * Change a ticket's category/priority (detail screen) and, when the priority
     * moved, recompute its SLA deadlines so the badges stay truthful.
     */
    public function updateClassification(
        Ticket $ticket,
        TicketPriority $priority,
        TicketCategory $category,
    ): Ticket {
        $priorityChanged = $ticket->priority !== $priority;

        $ticket->update([
            'priority' => $priority,
            'category' => $category,
        ]);

        if ($priorityChanged) {
            $this->applySla($ticket->refresh());
        }

        return $ticket;
    }

    /**
     * Stamp a requester satisfaction score (CSAT, 1-5) with an optional comment.
     * Clamped to the 1-5 band so a stray value never corrupts the reporting average.
     */
    public function recordCsat(Ticket $ticket, int $rating, ?string $comment = null): Ticket
    {
        $comment = $comment !== null ? trim($comment) : null;

        $ticket->update([
            'rating' => max(1, min(5, $rating)),
            'rating_comment' => $comment !== null && $comment !== '' ? $comment : null,
            'rated_at' => now(),
        ]);

        return $ticket->refresh();
    }

    /**
     * Choose who a ticket auto-routes to within the company. When a category is
     * known it prefers an on-call technician skilled in that category, then any
     * skilled technician, then — as a last resort — the least-loaded technician
     * overall so nothing stalls in the unassigned queue. Returns null only when
     * the company has no active technician.
     */
    private function resolveAutoAssignee(int $companyId, ?string $category = null): ?User
    {
        $technicians = User::query()
            ->with('skills')
            ->where('role', UserRole::Technician->value)
            ->where('is_active', true)
            ->where('company_id', $companyId)
            ->get();

        if ($technicians->isEmpty()) {
            return null;
        }

        // One grouped count for the whole company; Resolved/Closed are not "open".
        $open = Ticket::query()
            ->selectRaw('assigned_to, count(*) as aggregate')
            ->where('company_id', $companyId)
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
            ->groupBy('assigned_to')
            ->pluck('aggregate', 'assigned_to');

        $leastLoaded = fn (Collection $group) => $group
            ->sortBy(fn (User $t) => (int) $open->get($t->id, 0))
            ->first();

        if ($category !== null) {
            $skilled = $technicians->filter(fn (User $t) => $t->skills->contains('category_key', $category));

            $onCallSkilled = $skilled->filter(fn (User $t) => (bool) $t->is_on_call);

            if ($onCallSkilled->isNotEmpty()) {
                return $leastLoaded($onCallSkilled);
            }

            if ($skilled->isNotEmpty()) {
                return $leastLoaded($skilled);
            }
        }

        return $leastLoaded($technicians);
    }

    /**
     * Transition a ticket to a new status, enforcing the §5.2 matrix.
     *
     * @param  bool  $notifyEmployee  emit the §11 employee WhatsApp notification. Disabled
     *                                for WhatsApp-command-driven transitions that already reply inline.
     */
    public function changeStatus(
        Ticket $ticket,
        TicketStatus $to,
        ?User $actor = null,
        ?string $remark = null,
        bool $notifyEmployee = true,
    ): Ticket {
        $updated = DB::transaction(function () use ($ticket, $to, $actor, $remark) {
            $fresh = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            return $this->applyTransition($fresh, $to, $actor, $remark);
        });

        if ($notifyEmployee) {
            $this->notifyEmployeeTransition($updated, $to);
        }

        return $updated;
    }

    /**
     * Assign or reassign a technician. From New this also transitions to Assigned (PRD FR-06).
     */
    public function assignTechnician(
        Ticket $ticket,
        User $technician,
        ?User $actor = null,
        ?string $remarks = null,
        bool $notifyEmployee = true,
    ): Ticket {
        $updated = DB::transaction(function () use ($ticket, $technician, $actor, $remarks) {
            $fresh = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            $fresh->assigned_to = $technician->id;
            $note = trim(sprintf(
                'Assigned to %s%s',
                $technician->name,
                $remarks ? ' — '.$remarks : '',
            ));

            if ($fresh->status === TicketStatus::New) {
                return $this->applyTransition($fresh, TicketStatus::Assigned, $actor, $note);
            }

            $fresh->save();
            $this->recordHistory($fresh, $fresh->status, $fresh->status, $actor, $note);
            $fresh->load('assignee');
            $this->notifications->ticketAssigned($fresh);

            return $fresh;
        });

        if ($notifyEmployee) {
            $this->notifyEmployeeTransition($updated, TicketStatus::Assigned);
        }

        return $updated;
    }

    /**
     * Record a technician progress entry (§10.5). The first substantive entry
     * finalizes the draft initial report with the technician's fields (FR-10).
     *
     * @param  array<string, mixed>  $fields
     */
    public function addProgress(Ticket $ticket, User $author, array $fields): TicketProgressUpdate
    {
        return DB::transaction(function () use ($ticket, $author, $fields) {
            $update = TicketProgressUpdate::create([
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'work_performed' => $fields['work_performed'] ?? null,
                'findings' => $fields['findings'] ?? null,
                'update_text' => $fields['update_text'] ?? null,
                'next_action' => $fields['next_action'] ?? null,
                'minutes_spent' => $fields['minutes_spent'] ?? null,
                'is_internal' => (bool) ($fields['is_internal'] ?? false),
            ]);

            $draft = TicketProgressReport::query()
                ->where('ticket_id', $ticket->id)
                ->where('status', ReportStatus::Draft)
                ->first();

            if ($draft && ($update->work_performed || $update->findings || $update->next_action)) {
                $this->finalizeReport($draft, $author, [
                    'work_performed' => $update->work_performed,
                    'findings' => $update->findings,
                    'next_steps' => $update->next_action,
                ]);
            }

            return $update;
        });
    }

    /**
     * Finalize a draft initial report via the technician's first progress entry (PRD FR-10).
     */
    public function finalizeReport(
        TicketProgressReport $report,
        User $author,
        array $fields,
    ): TicketProgressReport {
        return DB::transaction(function () use ($report, $author, $fields) {
            $locked = TicketProgressReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            $locked->fill([
                'work_performed' => $fields['work_performed'] ?? $locked->work_performed,
                'findings' => $fields['findings'] ?? $locked->findings,
                'next_steps' => $fields['next_steps'] ?? $locked->next_steps,
            ]);

            $locked->status = ReportStatus::Final;
            $locked->prepared_by ??= $author->id;
            $locked->finalized_at = now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Emit the §11 employee WhatsApp notification for a lifecycle event. Gated behind
     * WHATSAPP_ENABLED (no live credentials -> nothing dispatched); the outbound
     * service applies the §11.2 window/template routing and persists the message so
     * its delivery state can be reconciled (§8.5, §11.4).
     */
    private function notifyEmployeeTransition(Ticket $ticket, TicketStatus $status): void
    {
        if (! config('services.whatsapp.enabled')) {
            return;
        }

        $ticket->loadMissing(['employee', 'assignee']);
        $employee = $ticket->employee;

        if (! $employee || ! $employee->whatsapp_number) {
            return;
        }

        $number = $ticket->ticket_number;
        $technician = $ticket->assignee?->name ?? 'a technician';

        [$session, $template, $params] = match ($status) {
            TicketStatus::Assigned => ["Ticket {$number} has been assigned to {$technician}.", 'ticket_assigned', [$number, $technician]],
            TicketStatus::InProgress => ["Work has started on ticket {$number}.", 'ticket_in_progress', [$number]],
            TicketStatus::Pending => ["Ticket {$number} is pending more information.", 'ticket_pending', [$number]],
            TicketStatus::Resolved => ["Ticket {$number} has been resolved.", 'ticket_resolved', [$number]],
            TicketStatus::Closed => ["Ticket {$number} has been closed.", 'ticket_closed', [$number]],
            default => [null, null, []],
        };

        if ($session === null) {
            return; // New carries no §11.3 template; intake handles acknowledgment.
        }

        $this->whatsapp->notifyTicket($ticket, $employee, $session, $template, $params);
    }

    /**
     * Apply a validated transition. Caller must already hold a transaction and row lock.
     */
    private function applyTransition(
        Ticket $ticket,
        TicketStatus $to,
        ?User $actor,
        ?string $remark,
    ): Ticket {
        $from = $ticket->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransition($from, $to);
        }

        if ($ticket->first_response_at === null && $to !== TicketStatus::New) {
            $ticket->first_response_at = now();
        }

        if ($to === TicketStatus::InProgress && $ticket->started_at === null) {
            $ticket->started_at = now();
            $this->createDraftInitialReport($ticket, $actor);
        }

        if ($to === TicketStatus::Resolved) {
            $ticket->resolved_at = now();
        }

        if ($to === TicketStatus::Closed) {
            $ticket->closed_at = now();
        }

        $ticket->status = $to;
        $ticket->save();

        $history = $this->recordHistory($ticket, $from, $to, $actor, $remark);

        // New-ticket and assignment events already carry their own notification
        // types; a plain transition emits StatusChanged (§7.8 event rows).
        if ($to === TicketStatus::Assigned) {
            $ticket->load('assignee');
            $this->notifications->ticketAssigned($ticket);
        } elseif ($from !== TicketStatus::New) {
            $this->notifications->statusChanged($ticket, $history);
        }

        return $ticket;
    }

    /**
     * Create the initial formal report as a Draft with only system-known fields (PRD FR-10).
     * Idempotent: at most one initial report per ticket (unique ticket_id + report_number).
     */
    private function createDraftInitialReport(Ticket $ticket, ?User $actor): void
    {
        TicketProgressReport::query()->firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'report_number' => 'INIT-'.$ticket->ticket_number,
            ],
            [
                'report_type' => ReportType::Initial,
                'status' => ReportStatus::Draft,
                'prepared_by' => $actor?->id ?? $ticket->assigned_to,
                'title' => $ticket->subject,
                'problem_summary' => $ticket->description,
                // work_performed / findings / next_steps intentionally left empty.
            ],
        );
    }

    private function recordHistory(
        Ticket $ticket,
        ?TicketStatus $from,
        TicketStatus $to,
        ?User $actor,
        ?string $remark,
    ): TicketStatusHistory {
        return TicketStatusHistory::create([
            'ticket_id' => $ticket->id,
            'changed_by' => $actor?->id,
            'from_status' => $from,
            'to_status' => $to,
            'remark' => $remark,
        ]);
    }
}
