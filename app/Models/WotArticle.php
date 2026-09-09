<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\WotArticleFactory;

/**
 * A news article from worldoftanks.com's RSS feed.
 *
 * @property-read bool $has_events
 */
#[Fillable(['guid', 'url', 'title', 'description', 'category', 'image_url', 'published_at', 'body_fetched_at', 'body_hash'])]
class WotArticle extends Model
{
    /** @use HasFactory<WotArticleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'body_fetched_at' => 'datetime',
        ];
    }

    protected function hasEvents(): Attribute
    {
        return Attribute::get(fn (): bool => $this->events_count > 0 || $this->events->isNotEmpty());
    }

    /**
     * Whether the body should be fetched to look for event dates.
     *
     * Re-fetching is driven by the feed rather than by a timer: an article
     * whose publish date hasn't moved since it was last read cannot have gained
     * new event markup, and this is someone else's server to be polite to.
     */
    public function needsBodyFetch(): bool
    {
        return $this->body_fetched_at === null
            || $this->published_at->gt($this->body_fetched_at);
    }

    // Scopes

    public function scopeOnlyWithEvents(Builder $query): Builder
    {
        return $query->whereHas('events');
    }
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('published_at');
    }

    // Relationships

    /** @return HasMany<WotEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(WotEvent::class);
    }
}
