<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Database\Factories\WotEventFactory;

/**
 * A dated occurrence extracted from an article body.
 */
#[Fillable(['wot_article_id', 'title', 'starts_at', 'ends_at', 'event_type', 'source', 'metadata'])]
class WotEvent extends Model
{
    /** @use HasFactory<WotEventFactory> */
    use HasFactory;

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

    /**
     * Drops what this user has told the calendar to stop showing. Used by every
     * view that displays events *as schedule* — the grid, the long-campaign
     * list, the dashboard's next five days.
     *
     * Not used by the "Coming up" listing, which is where an ignored event is
     * reconsidered and so has to keep showing it.
     */
    public function scopeNotIgnoredBy(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query;
        }

        return $query->whereDoesntHave('ignoredBy', fn (Builder $ignores) => $ignores->whereKey($user->id));
    }

    /**
     * Exposes whether this user has ignored each event, as an `ignored_at`
     * column.
     *
     * A correlated subquery rather than a join, for the reason
     * WotArticle::scopeWithSeenFor() gives: a join risks duplicating rows, and
     * addSelect composes with whatever ordering the caller has already applied.
     */
    public function scopeWithIgnoredFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->selectRaw('null as ignored_at');
        }

        return $query->addSelect(['ignored_at' => DB::table('wot_event_ignores')
            ->select('ignored_at')
            ->whereColumn('wot_event_ignores.wot_event_id', 'wot_events.id')
            ->where('wot_event_ignores.user_id', $user->id)
            ->limit(1),
        ]);
    }

    // Relationships

    public function article(): BelongsTo
    {
        return $this->belongsTo(WotArticle::class, 'wot_article_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function ignoredBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wot_event_ignores')->withPivot('ignored_at');
    }
}
