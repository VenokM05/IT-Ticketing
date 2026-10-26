<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'whatsapp_number',
        'email',
        'department_id',
        'location',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Normalize a phone number toward E.164 (digits and leading + only).
     * Full country-code resolution is validated at the enrollment layer (PRD §9.1).
     */
    public static function normalizeNumber(string $number): string
    {
        $digits = preg_replace('/(?!^\+)[^0-9]/', '', $number);

        return ltrim($digits, '+') === $digits
            ? $digits
            : '+'.$digits;
    }
}
