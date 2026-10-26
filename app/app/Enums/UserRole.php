<?php

namespace App\Enums;

/**
 * Dashboard roles (PRD FR-13). Employees have no dashboard account in the MVP.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'IT Administrator',
            self::Technician => 'IT Technician',
        };
    }
}
