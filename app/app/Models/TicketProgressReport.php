<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketProgressReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'prepared_by',
        'report_number',
        'report_type',
        'status',
        'title',
        'problem_summary',
        'work_performed',
        'findings',
        'next_steps',
        'reported_at',
        'finalized_at',
        'document_path',
    ];

    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'status' => ReportStatus::class,
            'reported_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function isDraft(): bool
    {
        return $this->status === ReportStatus::Draft;
    }

    public function isFinal(): bool
    {
        return $this->status === ReportStatus::Final;
    }
}
