<?php

namespace App\Services;

use App\Models\TicketNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique, gap-free per-year ticket numbers in the form IT-YYYY-NNNNNN
 * using a locked sequence row (PRD §7.12, FR-02).
 */
class TicketNumberGenerator
{
    /**
     * Reserve and return the next ticket number for the given year.
     */
    public function next(?int $year = null): string
    {
        $year ??= (int) date('Y');

        return DB::transaction(function () use ($year) {
            $sequence = TicketNumberSequence::query()
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = TicketNumberSequence::create([
                    'year' => $year,
                    'next_value' => 1,
                ]);
            }

            $value = $sequence->next_value;

            $sequence->update(['next_value' => $value + 1]);

            return sprintf('IT-%d-%06d', $year, $value);
        });
    }
}
