<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A knowledge-base article (PRD Phase 10, Rec #14). Only {@link published()}
 * rows surface to requesters; drafts stay hidden until an admin flips the switch.
 * A null `category_key` means the article is generic and offered for every category.
 */
class KbArticle extends Model
{
    use HasFactory;

    protected $table = 'kb_articles';

    protected $fillable = ['title', 'body', 'category_key', 'published', 'created_by'];

    protected $casts = ['published' => 'boolean'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    /**
     * Published articles to suggest for a chosen intake category: those tagged
     * with the exact key plus any general (untagged) ones, most-recent first.
     *
     * @return Collection<int, self>
     */
    public static function suggestionsFor(?string $categoryKey, int $limit = 3): Collection
    {
        return static::query()
            ->published()
            ->when($categoryKey !== null && $categoryKey !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('category_key', $categoryKey)
                ->orWhereNull('category_key')))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }
}
