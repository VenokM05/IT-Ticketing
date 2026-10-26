<?php

namespace App\Enums;

enum WebhookProcessingStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
