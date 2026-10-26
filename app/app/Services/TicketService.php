<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Ticket;
use App\Models\TicketProgressReport;
use App\Models\TicketStatusHistory;
use App\Models\User;
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
    ) {
    }

    /**
     * Create a new ticket from validated intake data (PRD FR-02).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createTicket(array $attributes, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $ticket = Ticket::create([
                ...$attributes,
                'ticket_number' => $this->numbers->next(),
                'status' => $attributes['status'] ?? TicketStatus::New,
            ]);

            $this->recordHistory($ticket, null, $ticket->status, $actor, 'Ticket created');
            $this->notifications->ticketCreated($ticket);

            return $ticket;
        });
    }

    /**
     * Transition a ticket to a new status, enforcing the §5.2 matrix.
     */
    public function changeStatus(
        Ticket $ticket,
        TicketStatus $to,
        ?User $actor = null,
        ?string $remark = null,
    ): Ticket {
        return DB::transaction(function () use ($ticket, $to, $actor, $remark) {
            $fresh = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            return $this->applyTransition($fresh, $to, $actor, $remark);
        });
    }

    /**
     * Assign or reassign a technician. From New this also transitions to Assigned (PRD FR-06).
     */
    public function assignTechnician(
        Ticket $ticket,
        User $technician,
        ?User $actor = null,
        ?string $remarks = null,
    ): Ticket {
        return DB::transaction(function () use ($ticket, $technician, $actor, $remarks) {
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
