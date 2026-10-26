<?php

namespace App\Enums;

/**
 * Formal report classification (PRD FR-10).
 */
enum ReportType: string
{
    case Initial = 'initial';
    case Subsequent = 'subsequent';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial',
            self::Subsequent => 'Subsequent',
        };
    }
}
