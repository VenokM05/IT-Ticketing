<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'direction',
        'channel',
        'message_type',
        'body',
        'whatsapp_message_id',
        'delivery_status',
        'is_internal',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'channel' => MessageChannel::class,
            'message_type' => MessageType::class,
            'delivery_status' => DeliveryStatus::class,
            'is_internal' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
