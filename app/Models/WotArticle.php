<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
        // The id tiebreaker is not cosmetic. Many articles share a publish date,
        // and without a deterministic final sort the database is free to return
        // tied rows in a different order per query — which, across a paginated
        // set, can show one article on two pages and another on none.
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Newest first, but with this user's pinned articles hoisted above
     * everything else as a group. Pinning only groups; it does not reorder
     * within the group, so a pinned article still sits by `published_at`
     * among the other pins.
     *
     * A left join rather than a `whereHas`, because pinned-ness has to be
     * available to ORDER BY — and this way one query still serves the
     * paginator. `wot_articles.*` is selected explicitly since the join puts
     * an `id` on both sides.
     */
    public function scopePinnedFirstFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->inDefaultOrder();
        }

        return $query
            ->leftJoin('wot_article_pins', function ($join) use ($user): void {
                $join->on('wot_article_pins.wot_article_id', '=', 'wot_articles.id')
                    ->where('wot_article_pins.user_id', '=', $user->id);
            })
            // addSelect, not select: select() resets the column list, which
            // silently discarded withSeenFor()'s subquery when the two scopes
            // were chained in the other order. Qualified because the join puts
            // an `id` on both sides.
            ->addSelect('wot_articles.*')
            ->addSelect('wot_article_pins.pinned_at as pinned_at')
            // Postgres sorts false before true, so "is null" ascending puts the
            // pinned rows first without needing a CASE expression.
            ->orderByRaw('wot_article_pins.pinned_at is null')
            ->orderByDesc('wot_articles.published_at')
            // Deterministic tiebreaker; see inDefaultOrder().
            ->orderByDesc('wot_articles.id');
    }

    public function scopeOnlyPinnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('pinnedBy', fn (Builder $pins) => $pins->whereKey($user->id));
    }

    /**
     * Exposes when this user saw each article, as a `seen_at` column.
     *
     * A correlated subquery rather than another left join: a second join would
     * risk duplicating rows, and a subquery composes with pinnedFirstFor() in
     * either order. Both scopes use addSelect for the same reason.
     */
    public function scopeWithSeenFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->selectRaw('null as seen_at');
        }

        return $query->addSelect(['seen_at' => DB::table('wot_article_views')
            ->select('seen_at')
            ->whereColumn('wot_article_views.wot_article_id', 'wot_articles.id')
            ->where('wot_article_views.user_id', $user->id)
            ->limit(1),
        ]);
    }

    public function scopeOnlyUnseenBy(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('seenBy', fn (Builder $views) => $views->whereKey($user->id));
    }

    // Relationships

    /** @return HasMany<WotEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(WotEvent::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function pinnedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wot_article_pins')->withPivot('pinned_at');
    }

    /** @return BelongsToMany<User, $this> */
    public function seenBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wot_article_views')->withPivot('seen_at');
    }
}
