<?php

namespace App\Enums;

/**
 * Initial internal-IT request categories (PRD §1.3).
 * Values are confirmed during Phase 1 and may be moved to a managed lookup table later.
 */
enum TicketCategory: string
{
    case Hardware = 'hardware';
    case Software = 'software';
    case Network = 'network';
    case Printer = 'printer';
    case Access = 'access';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'Access/Permissions',
            self::General => 'General IT Support',
            default => ucfirst($this->value),
        };
    }
}
