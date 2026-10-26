<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use App\Enums\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'event_type',
        'processing_status',
        'payload',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'processing_status' => WebhookProcessingStatus::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Build the composite idempotency key "{type}:{wa_message_id}" (PRD §8.3).
     */
    public static function buildEventKey(WebhookEventType $type, string $whatsappMessageId): string
    {
        return $type->value.':'.$whatsappMessageId;
    }
}
