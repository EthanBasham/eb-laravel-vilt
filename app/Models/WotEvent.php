<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Database\Factories\WotEventFactory;

/**
 * A dated occurrence extracted from an article body.
 *
 * @property-read bool $is_long_running
 * @property-read array{id: int, title: string, type: ?string, source: string, starts_at: string, ends_at: ?string, article: array{title: ?string, url: ?string}} $list_item_props
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

    /**
     * Spans longer than a week, which would otherwise occupy every square of a
     * schedule view, so the calendar and the dashboard list it apart.
     *
     * Only ever true of window events: a calendar session is a single sitting,
     * so a long one would mean the extraction misread something.
     */
    protected function isLongRunning(): Attribute
    {
        return Attribute::get(fn (): bool => $this->ends_at !== null && $this->starts_at->diffInDays($this->ends_at) > 7);
    }

    /**
     * The fields every event listing sends, wherever it is drawn: the month
     * grid, the long-campaign list, "Coming up" and the dashboard's next five
     * days. Each adds its own extras at its call site.
     *
     * Load the article with the query (`with('article:id,title,url')`), or
     * reading it here lazy-loads it one row at a time.
     */
    protected function listItemProps(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->event_type,
            'source' => $this->source,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'article' => ['title' => $this->article?->title, 'url' => $this->article?->url],
        ]);
    }

    // Event : Day

    /**
     * Whether any part of the event falls on $day. With no end it is a single
     * moment, so only its start counts, as in onlyOverlappingDays().
     */
    public function occursOn(Carbon $day): bool
    {
        return $this->starts_at->lte($day->copy()->endOfDay())
            && ($this->ends_at ?? $this->starts_at)->gte($day->copy()->startOfDay());
    }

    /**
     * The start time to show on $day's square, if any. Only a calendar session
     * that actually starts on that day shows one: a multi-day window rendered
     * with "16:00" on every square would be stating something untrue.
     */
    public function startTimeOn(Carbon $day): ?string
    {
        if ($this->source === self::SOURCE_CALENDAR && $this->starts_at->isSameDay($day)) {
            return $this->starts_at->format('H:i');
        }

        return null;
    }
    /**
     * The end time to show on $day's square, by the same rule as startTimeOn().
     */
    public function endTimeOn(Carbon $day): ?string
    {
        if ($this->source === self::SOURCE_CALENDAR && $this->ends_at?->isSameDay($day)) {
            return $this->ends_at->format('H:i');
        }

        return null;
    }
    /**
     * Whether $day is the last square of a run that actually spans days. A
     * single sitting is excluded deliberately: every one-day event would
     * otherwise announce itself as ending, which says nothing.
     */
    public function isFinalDayOn(Carbon $day): bool
    {
        return $this->ends_at !== null
            && $this->ends_at->isSameDay($day)
            && ! $this->starts_at->isSameDay($this->ends_at);
    }

    // Event : Ignore
    public function ignoreBy(User $user): void
    {
        $this->ignoredBy()->syncWithoutDetaching([
            $user->id => ['ignored_at' => now()],
        ]);
    }
    public function unignoreBy(User $user): void
    {
        $this->ignoredBy()->detach($user->id);
    }

    // Scopes

    /**
     * Soonest first. The id tiebreaker keeps sessions sharing a start time in
     * a stable order, which matters under a limit: "Coming up" takes ten, and
     * without it which of two tied events made the cut could change per query.
     */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('wot_events.starts_at')->orderBy('wot_events.id');
    }
    public function scopeOnlyBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        // An event counts as "in range" if it overlaps the range at all, not
        // only if it starts inside it — a month-long campaign should appear on
        // every month it spans, not just the one it began in. A calendar
        // session with no end time is a single moment, not an open run, which
        // is onlyOverlappingDays()'s default.
        return $query->onlyOverlappingDays('starts_at', 'ends_at', $from, $to);
    }
    public function scopeOnlyUpcoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('ends_at', '>=', now())->orWhere('starts_at', '>=', now()));
    }

    public function scopeNotIgnoredBy(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query;
        }

        return $query->whereDoesntHave('ignoredBy', fn (Builder $ignores) => $ignores->whereKey($user->id));
    }
    public function scopeWithIgnoredFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->selectRaw('null as ignored_at');
        }

        return $query->addSelect([
            'ignored_at' => DB::table('wot_event_ignores')
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
