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
