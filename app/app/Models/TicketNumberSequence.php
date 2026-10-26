<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-year counter backing safe concurrent ticket-number generation (PRD §7.12).
 */
class TicketNumberSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'next_value',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_value' => 'integer',
        ];
    }
}
