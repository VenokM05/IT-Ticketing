<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A DB-managed ticket priority (PRD Phase 9, Rec #1). Promoted from the old
 * TicketPriority enum and now carries the per-priority SLA hour budgets that
 * used to live in config('tickets.sla') (Phase 1). The {@link $key} is the
 * stable identifier; a `value` accessor mirrors the enum's `->value` so the
 * existing `$ticket->priority->value` / `->label()` call sites keep working.
 */
class Priority extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'label', 'sla_response_hours', 'sla_resolve_hours', 'is_active', 'sort'];

    protected $casts = [
        'sla_response_hours' => 'integer',
        'sla_resolve_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Present the stable key under the enum-compatible `value` name. */
    protected $appends = ['value'];

    public function getValueAttribute(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return (string) $this->label;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('label');
    }

    /**
     * Resolve a priority from a model, enum or key string. A missing row is
     * created and seeded from config('tickets.sla') so SLA deadlines keep being
     * stamped even on an unseeded (test) database.
     */
    public static function resolve(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        $key = $value instanceof \BackedEnum ? $value->value : trim((string) $value);

        if ($key === '') {
            return null;
        }

        $window = config('tickets.sla')[$key] ?? null;

        return static::firstOrCreate(['key' => $key], [
            'label' => ucfirst($key),
            'sla_response_hours' => $window['response'] ?? null,
            'sla_resolve_hours' => $window['resolve'] ?? null,
        ]);
    }
}
