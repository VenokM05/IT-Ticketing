<?php

namespace App\Models;

use App\Enums\ReportType;
use App\Enums\TicketStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'employee_id',
        'company_id',
        'assigned_to',
        'subject',
        'description',
        'category_id',
        'priority_id',
        'status',
        'first_response_at',
        'started_at',
        'resolved_at',
        'closed_at',
        'response_due_at',
        'resolve_due_at',
        // Requester satisfaction captured at resolve/close (CSAT, Phase 6).
        'rating',
        'rating_comment',
        'rated_at',
        // Self-declared requester snapshot for public, no-login submissions.
        'requester_name',
        'requester_contact',
        'requester_company',
        'requester_department',
        'requester_position',
        // Optional email captured at public intake for the receipt + link recovery.
        'requester_email',
        // Optional remote-assistance handle supplied at intake (web forms).
        'anydesk_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'first_response_at' => 'datetime',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolve_due_at' => 'datetime',
            'rating' => 'integer',
            'rated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Denormalized company of the requester, stamped at intake (multi-company tagging).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * DB-managed classification (PRD Phase 9). The category/priority relations
     * replace the old enum casts; a `value` accessor + `label()` method on each
     * lookup model keeps `$ticket->category->value` / `->label()` call sites intact.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(TicketProgressUpdate::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TicketProgressReport::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class);
    }

    /**
     * The initial formal report created on the first In Progress transition (PRD FR-10).
     */
    public function initialReport()
    {
        return $this->hasOne(TicketProgressReport::class)
            ->where('report_type', ReportType::Initial->value);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /*
    |--------------------------------------------------------------------------
    | Unified requester display
    |--------------------------------------------------------------------------
    |
    | A ticket is either tied to an enrolled employee (WhatsApp / admin intake) or
    | carries only the self-declared snapshot from a public, no-login submission.
    | These accessors prefer the enrollment and fall back to the snapshot, so the
    | dashboard and detail views need no branching.
    */

    public function requesterName(): ?string
    {
        return $this->employee?->name ?? $this->requester_name;
    }

    public function requesterContact(): ?string
    {
        return $this->employee?->whatsapp_number
            ?? $this->employee?->email
            ?? $this->requester_contact
            ?? $this->requester_email;
    }

    /**
     * The address the intake receipt was mailed to, when the requester gave one.
     */
    public function requesterEmail(): ?string
    {
        return $this->employee?->email ?? $this->requester_email;
    }

    public function requesterDepartment(): ?string
    {
        return $this->employee?->department?->name ?? $this->requester_department;
    }

    public function requesterPosition(): ?string
    {
        return $this->employee?->position ?? $this->requester_position;
    }

    /**
     * The company label: the stamped tag when known, the typed claim when the
     * public request named an unrecognised company, else the Unassigned bucket.
     */
    public function companyLabel(): string
    {
        return $this->company?->label()
            ?? $this->requester_company
            ?? 'Unassigned';
    }

    public function hasStarted(): bool
    {
        return $this->started_at !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | SLA state
    |--------------------------------------------------------------------------
    |
    | Deadlines are calendar-hour budgets stamped from config('tickets.sla') at
    | intake (and recomputed when priority changes). These accessors classify the
    | response/resolve milestones as met, breached, or at-risk so the UI can badge
    | them without duplicating the date maths. A null state means no deadline is
    | tracked for this ticket.
    */

    /**
     * @return 'ok'|'at_risk'|'breached'|null
     */
    public function slaResponseState(): ?string
    {
        return $this->slaState($this->response_due_at, $this->first_response_at);
    }

    /**
     * @return 'ok'|'at_risk'|'breached'|null
     */
    public function slaResolveState(): ?string
    {
        return $this->slaState($this->resolve_due_at, $this->resolved_at);
    }

    /**
     * The worse of the two milestone states (breached > at_risk > ok), for a single
     * list badge. Null when neither deadline is tracked.
     */
    public function slaSummaryState(): ?string
    {
        $states = array_filter([$this->slaResponseState(), $this->slaResolveState()]);

        if ($states === []) {
            return null;
        }

        return in_array('breached', $states, true)
            ? 'breached'
            : (in_array('at_risk', $states, true) ? 'at_risk' : 'ok');
    }

    /**
     * Classify one milestone: reached it before the deadline -> ok; blew past it
     * unreached -> breached; unreached but inside the at-risk tail -> at_risk.
     */
    private function slaState(?Carbon $dueAt, ?Carbon $reachedAt): ?string
    {
        if ($dueAt === null) {
            return null;
        }

        if ($reachedAt !== null) {
            return $reachedAt->lte($dueAt) ? 'ok' : 'breached';
        }

        $now = now();

        if ($now->gt($dueAt)) {
            return 'breached';
        }

        // Fraction of the window already elapsed since creation.
        $total = $this->created_at?->diffInSeconds($dueAt) ?: 0;
        $elapsed = $this->created_at ? $this->created_at->diffInSeconds($now) : 0;
        $ratio = $total > 0 ? $elapsed / $total : 1;

        return $ratio >= (float) config('tickets.sla_at_risk_ratio', 0.75) ? 'at_risk' : 'ok';
    }

    /**
     * Tickets that are not yet closed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', TicketStatus::Closed->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('assigned_to');
    }

    /**
     * @param  Builder<self>  $query
     * @param  Builder<self>  $query
     */
    public function scopeAssignedTo(Builder $query, int|User $user): void
    {
        $query->where('assigned_to', $user instanceof User ? $user->id : $user);
    }
}
