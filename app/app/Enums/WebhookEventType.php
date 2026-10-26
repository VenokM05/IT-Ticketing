<?php

namespace App\Enums;

/**
 * WhatsApp webhook event classes. Combined with the WhatsApp message ID to form
 * the composite idempotency key `{type}:{wa_message_id}` (PRD §8.3).
 */
enum WebhookEventType: string
{
    case Inbound = 'inbound';
    case Status = 'status';

    public static function tryFromTypeString(?string $value): ?self
    {
        return match ($value) {
            'messages', 'inbound' => self::Inbound,
            'status', 'statuses' => self::Status,
            default => null,
        };
    }
}
