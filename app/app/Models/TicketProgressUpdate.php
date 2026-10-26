<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketProgressUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'work_performed',
        'findings',
        'update_text',
        'next_action',
        'minutes_spent',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'minutes_spent' => 'integer',
            'is_internal' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
