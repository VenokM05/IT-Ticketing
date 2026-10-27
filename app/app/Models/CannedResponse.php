<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'category_key',
        'created_by',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Substitute the supported placeholders against a ticket. Unknown tokens are
     * left untouched so a malformed macro never wipes the author's text.
     */
    public function render(Ticket $ticket): string
    {
        return str_replace(
            [
                '{{ticket_number}}',
                '{{requester_name}}',
                '{{subject}}',
                '{{technician}}',
            ],
            [
                $ticket->ticket_number,
                $ticket->requesterName() ?? '',
                $ticket->subject,
                $ticket->assignee?->name ?? '',
            ],
            $this->body,
        );
    }
}
