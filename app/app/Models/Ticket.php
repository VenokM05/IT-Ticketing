<?php

namespace App\Models;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
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
        'category',
        'priority',
        'status',
        'first_response_at',
        'started_at',
        'resolved_at',
        'closed_at',
        // Self-declared requester snapshot for public, no-login submissions.
        'requester_name',
        'requester_contact',
        'requester_company',
        'requester_department',
        'requester_position',
        // Optional remote-assistance handle supplied at intake (web forms).
        'anydesk_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'category' => TicketCategory::class,
            'first_response_at' => 'datetime',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
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
            ->where('report_type', \App\Enums\ReportType::Initial->value);
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
            ?? $this->requester_contact;
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
