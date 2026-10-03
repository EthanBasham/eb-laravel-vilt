<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\WotArticleFactory;

/**
 * A news article from worldoftanks.com's RSS feed.
 *
 * @property-read bool $has_events
 * @property-read array{id: int, title: string, url: string, category: ?string, image_url: ?string, published_at: string, is_pinned: bool, is_seen: bool} $list_item_props
 */
#[Fillable(['guid', 'url', 'title', 'description', 'category', 'image_url', 'published_at', 'body_fetched_at', 'body_hash'])]
class WotArticle extends Model
{
    /** @use HasFactory<WotArticleFactory> */
    use HasFactory;

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

    protected function listItemProps(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'category' => $this->category,
            'image_url' => $this->image_url,
            'published_at' => $this->published_at->toIso8601String(),
            'is_pinned' => $this->pinned_at !== null,
            'is_seen' => $this->seen_at !== null,
        ]);
    }

    // Article : Mark Seen
    public function markSeenBy(User $user): void
    {
        DB::table('wot_article_views')->insertOrIgnore([
            'user_id' => $user->id,
            'wot_article_id' => $this->id,
            'seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    public static function markAllSeenBy(User $user): void
    {
        DB::table('wot_article_views')->insertOrIgnoreUsing(
            ['user_id', 'wot_article_id', 'seen_at', 'created_at', 'updated_at'],
            static::query()->notSeenBy($user)
                ->selectRaw('? as user_id', [$user->id])
                ->addSelect('wot_articles.id')
                ->selectRaw('? as seen_at', [now()])
                ->selectRaw('? as created_at', [now()])
                ->selectRaw('? as updated_at', [now()]),
        );
    }
    public static function markAllUnseenBy(User $user): void
    {
        DB::table('wot_article_views')->where('user_id', $user->id)->delete();
    }

    // Article : Pin
    public function pinBy(User $user): void
    {
        $this->pinnedBy()->syncWithoutDetaching([
            $user->id => ['pinned_at' => now()],
        ]);
    }
    public function unpinBy(User $user): void
    {
        $this->pinnedBy()->detach($user->id);
    }

    // Article : Events

    /**
     * Brings this article's events in line with a fresh extraction, updating
     * the rows that are still there rather than replacing them, so an event
     * keeps its id — and the ignores hung off it — across a resync.
     *
     * Matched in passes, each over what the previous ones left unpaired:
     * unchanged (source, title, start), rescheduled (source, title), renamed
     * (source, start), and finally the article's one window, whatever changed.
     * Within a pass, rows sharing a key pair in date order, so a recurring
     * session that shifts a day pairs first with first. Whatever is still
     * unpaired is created or hard-deleted; a deleted event takes its ignores
     * with it, which is right when it has gone from the article.
     *
     * @param  list<array<string, mixed>>  $extracted  EventExtractor::extract() output.
     * @return array{kept: int, moved: int, renamed: int, created: int, removed: int}
     */
    public function syncEvents(array $extracted): array
    {
        $existing = $this->events()->inDefaultOrder()->get();
        $incoming = collect($extracted)->sortBy(fn (array $event): int => $event['starts_at']->getTimestamp())->values();

        $passes = [
            ['kept', fn (string $source, string $title, CarbonInterface $startsAt): string => "{$source}|{$title}|{$startsAt->getTimestamp()}"],
            ['moved', fn (string $source, string $title): string => "{$source}|{$title}"],
            ['renamed', fn (string $source, string $title, CarbonInterface $startsAt): string => "{$source}|{$startsAt->getTimestamp()}"],
            ['moved', fn (string $source): ?string => $source === WotEvent::SOURCE_WINDOW ? $source : null],
        ];

        $counts = ['kept' => 0, 'moved' => 0, 'renamed' => 0, 'created' => 0, 'removed' => 0];
        $updates = [];

        foreach ($passes as [$label, $key]) {
            [$pairs, $existing, $incoming] = $this->pairEvents($existing, $incoming, $key);

            $counts[$label] += count($pairs);
            $updates = [...$updates, ...$pairs];
        }

        $counts['created'] = $incoming->count();
        $counts['removed'] = $existing->count();

        // Deletes first: an unpaired row can't hold a key an update is about to
        // take (it would have paired with it), but clearing it before anything
        // moves keeps the unique index out of the question entirely.
        DB::transaction(function () use ($existing, $updates, $incoming): void {
            $this->events()->whereKey($existing->modelKeys())->delete();

            foreach ($updates as [$event, $attributes]) {
                $event->update($attributes);
            }

            foreach ($incoming as $attributes) {
                $this->events()->create($attributes);
            }
        });

        return $counts;
    }
    /**
     * Pairs each incoming event with the earliest existing one sharing its key.
     * A null key opts that row out of the pass.
     *
     * @param  Collection<int, WotEvent>  $existing
     * @param  Collection<int, array<string, mixed>>  $incoming
     * @return array{0: list<array{0: WotEvent, 1: array<string, mixed>}>, 1: Collection<int, WotEvent>, 2: Collection<int, array<string, mixed>>}
     */
    private function pairEvents(Collection $existing, Collection $incoming, Closure $key): array
    {
        $pairs = [];
        $unpaired = collect();

        foreach ($incoming as $attributes) {
            $wanted = $key($attributes['source'], $attributes['title'], $attributes['starts_at']);

            $index = $wanted === null
                ? false
                : $existing->search(fn (WotEvent $event): bool => $key($event->source, $event->title, $event->starts_at) === $wanted);

            if ($index === false) {
                $unpaired->push($attributes);

                continue;
            }

            $pairs[] = [$existing->pull($index), $attributes];
        }

        return [$pairs, $existing, $unpaired];
    }

    // Scopes

    public function scopeOnlyWithEvents(Builder $query): Builder
    {
        return $query->whereHas('events');
    }
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('wot_articles.published_at')->orderByDesc('wot_articles.id');
    }

    public function scopeWithPinnedFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->selectRaw('null as pinned_at');
        }

        return $query
            ->leftJoin('wot_article_pins', function ($join) use ($user): void {
                $join->on('wot_article_pins.wot_article_id', '=', 'wot_articles.id')
                    ->where('wot_article_pins.user_id', '=', $user->id);
            })
            ->addSelect('wot_articles.*')
            ->addSelect('wot_article_pins.pinned_at as pinned_at');
    }
    public function scopeOnlyPinnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('pinnedBy', fn (Builder $pins) => $pins->whereKey($user->id));
    }
    public function scopeInPinnedFirstOrder(Builder $query): Builder
    {
        return $query->orderByRaw('wot_article_pins.pinned_at is null')->inDefaultOrder();
    }

    public function scopeWithSeenFor(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->selectRaw('null as seen_at');
        }

        return $query->addSelect([
            'seen_at' => DB::table('wot_article_views')
                ->select('seen_at')
                ->whereColumn('wot_article_views.wot_article_id', 'wot_articles.id')
                ->where('wot_article_views.user_id', $user->id)
                ->limit(1),
        ]);
    }
    public function scopeOnlySeenBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('seenBy', fn (Builder $views) => $views->whereKey($user->id));
    }
    public function scopeNotSeenBy(Builder $query, User $user): Builder
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
