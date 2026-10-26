<?php

namespace App\Enums;

/**
 * Formal progress report lifecycle (PRD FR-10, §2.2).
 * A report is created as a Draft on the first In Progress transition with only
 * system-known fields populated, then finalized by the technician's first progress entry.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case Final = 'final';
}
