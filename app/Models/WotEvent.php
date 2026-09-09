<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated occurrence extracted from an article body.
 */
#[Fillable(['wot_article_id', 'title', 'starts_at', 'ends_at', 'event_type', 'source', 'metadata'])]
class WotEvent extends Model
{
    /** Exact per-day session from the site's event-calendar component. */
    public const SOURCE_CALENDAR = 'calendar';

    /** A coarse overall window from a pair of data-timestamp attributes. */
    public const SOURCE_WINDOW = 'window';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    // Scopes

    public function scopeOnlyBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        // An event counts as "in range" if it overlaps the range at all, not
        // only if it starts inside it — a month-long campaign should appear on
        // every month it spans, not just the one it began in.
        return $query->where('starts_at', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $from))
            ->where(fn (Builder $q) => $q->whereNotNull('ends_at')->orWhere('starts_at', '>=', $from));
    }
    public function scopeOnlyUpcoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('ends_at', '>=', now())->orWhere('starts_at', '>=', now()));
    }

    // Relationships

    public function article(): BelongsTo
    {
        return $this->belongsTo(WotArticle::class, 'wot_article_id');
    }
}
