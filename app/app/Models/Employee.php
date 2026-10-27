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
        'company_id',
        'position',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    /**
     * Find an active employee by WhatsApp number, tolerant of a leading '+' and
     * formatting differences. WhatsApp's webhook "from" is bare digits, while
     * records may be stored with a '+' (PRD §9.1, §9.2).
     */
    public static function findByNumber(?string $raw): ?self
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        return static::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('whatsapp_number', $digits)->orWhere('whatsapp_number', '+'.$digits))
            ->first();
    }
}
