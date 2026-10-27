<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A DB-managed request category (PRD Phase 9, Rec #1). Promoted from the old
 * TicketCategory enum: the machine {@link $key} is what skills and canned
 * responses still reference (as category_key), while {@link $label} is the
 * editable display name. A `value` accessor mirrors the enum's `->value` so the
 * many existing `$ticket->category->value` / `->label()` call sites keep working.
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'label', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean'];

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
     * Resolve a category from a model, enum or key string (self-heals a missing
     * row so intake and factories never fail on an unseeded database).
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

        return static::firstOrCreate(['key' => $key], ['label' => static::defaultLabel($key)]);
    }

    /** Human label for a freshly-created key, matching the original enum copy. */
    public static function defaultLabel(string $key): string
    {
        return match ($key) {
            'access' => 'Access/Permissions',
            'general' => 'General IT Support',
            default => ucfirst($key),
        };
    }
}
