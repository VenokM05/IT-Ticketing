<?php

namespace App\Enums;

/**
 * Outbound message lifecycle (PRD §8.5). "Queued" is local; the rest mirror
 * WhatsApp delivery receipts and must be reconciled against webhook status events.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}
