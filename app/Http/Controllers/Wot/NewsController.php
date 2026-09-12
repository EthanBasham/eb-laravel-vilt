<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\MarkArticlesSeenRequest;
use App\Models\WotArticle;
use App\Models\WotEvent;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $request->string('category')->toString() ?: null;
        $pinnedOnly = $request->boolean('pinned');
        $user = $request->user();

        return Inertia::render('News', [
            // A closure so Inertia partial reloads (the seen-tracker flushes
            // ask only for unseenCount) skip this query entirely.
            'articles' => fn () => WotArticle::query()
                // Qualified: pinnedFirstFor joins wot_article_pins, which also
                // has a category-free `id`, so an unqualified column here would
                // be ambiguous.
                ->when($category, fn ($query) => $query->where('wot_articles.category', $category))
                ->when($pinnedOnly, fn ($query) => $query->onlyPinnedBy($user))
                ->withCount('events')
                ->pinnedFirstFor($user)
                ->withSeenFor($user)
                ->paginate(24)
                ->withQueryString()
                ->through(fn (WotArticle $article): array => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'url' => $article->url,
                    'description' => $article->description,
                    'category' => $article->category,
                    'image_url' => $article->image_url,
                    'published_at' => $article->published_at->toIso8601String(),
                    'events_count' => $article->events_count,
                    // pinned_at comes from the join in pinnedFirstFor(); its
                    // presence is what "pinned" means here.
                    'is_pinned' => $article->pinned_at !== null,
                    'is_seen' => $article->seen_at !== null,
                ]),
            'categories' => WotArticle::query()
                ->select('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->filter()
                ->values(),
            'activeCategory' => $category,
            'pinnedOnly' => $pinnedOnly,
            'pinnedCount' => $user->pinnedArticles()->count(),
            'unseenCount' => WotArticle::onlyUnseenBy($user)->count(),
        ]);
    }

    /**
     * Marks a batch of articles seen.
     *
     * A batch rather than one call per article: the client observes cards
     * scrolling past and would otherwise fire a request every second or so
     * during a scroll.
     */
    public function markSeen(MarkArticlesSeenRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Only ids that exist are marked, so a stale client sending an id for a
        // since-deleted article can't fail the whole batch.
        $ids = WotArticle::whereIn('id', $request->validated('ids'))->pluck('id');

        // attach(), not syncWithoutDetaching(). The latter calls
        // updateExistingPivot for ids already present, which would rewrite
        // seen_at every time a card scrolled past again — and "first seen" is
        // the fact worth keeping. Already-seen ids are filtered out instead.
        $unseen = $ids->diff(
            $user->seenArticles()->whereIn('wot_articles.id', $ids)->pluck('wot_articles.id'),
        );

        $user->seenArticles()->attach(
            $unseen->mapWithKeys(fn (int $id): array => [$id => ['seen_at' => now()]])->all(),
        );

        return back(fallback: route('wot.news.index'));
    }

    /**
     * Clears the whole backlog — the escape hatch for coming back after a
     * while and not wanting to scroll past two months of articles.
     */
    public function markAllSeen(Request $request): RedirectResponse
    {
        $user = $request->user();

        $unseen = WotArticle::onlyUnseenBy($user)->pluck('id');

        // Chunked because this is unbounded: it grows with the feed, and a
        // single insert of every article a user has never seen is the one place
        // here that could get large.
        foreach ($unseen->chunk(500) as $chunk) {
            // Safe to attach outright: the query above selected only rows with
            // no existing pivot.
            $user->seenArticles()->attach(
                $chunk->mapWithKeys(fn (int $id): array => [$id => ['seen_at' => now()]])->all(),
            );
        }

        // No flash: every NEW badge and the button itself disappear, which
        // reports the outcome more directly than a banner restating it.
        return back(fallback: route('wot.news.index'));
    }

    /**
     * Pin an article to the top of this user's feed.
     *
     * Idempotent: pinning something already pinned refreshes pinned_at rather
     * than failing on the unique constraint. That timestamp no longer drives
     * ordering — see WotArticle::scopePinnedFirstFor() — but is kept current
     * for the "pinned" state itself.
     */
    public function pin(Request $request, WotArticle $article): RedirectResponse
    {
        // syncWithoutDetaching already refreshes pinned_at on a row that exists
        // — its attachNew() calls updateExistingPivot for ids already present —
        // so a second call isn't needed here.
        $request->user()->pinnedArticles()->syncWithoutDetaching([
            $article->id => ['pinned_at' => now()],
        ]);

        // No flash message: the card gains its pinned styling and jumps into
        // the pinned group, which says it more directly than a banner would.
        return back(fallback: route('wot.news.index'));
    }

    public function unpin(Request $request, WotArticle $article): RedirectResponse
    {
        $request->user()->pinnedArticles()->detach($article->id);

        return back(fallback: route('wot.news.index'));
    }

    /**
     * Runs the scheduled news/event sync immediately, for whenever three times
     * a day isn't soon enough.
     *
     * The command fetches up to a dozen-plus article bodies with a deliberate
     * pace between requests, so this can take a while — lift the PHP time
     * limit rather than have it get cut off mid-run.
     */
    public function resync(): RedirectResponse
    {
        set_time_limit(0);

        Artisan::call('wot:sync-news');

        return back(fallback: route('wot.news.index'))->with('success', 'News and calendar resynced.');
    }

    /**
     * A month of events.
     *
     * Days are assembled server-side rather than in Vue because a window event
     * spans many days and has to appear on each of them — resolving that once
     * here is simpler than teaching the calendar component to expand ranges,
     * and it keeps the payload to exactly what is rendered.
     */
    public function calendar(Request $request): Response
    {
        $month = rescue(
            fn (): Carbon => Carbon::createFromFormat('Y-m', $request->string('month')->toString())->startOfMonth(),
            fn (): Carbon => Carbon::now()->startOfMonth(),
            report: false,
        );

        // The grid is whole weeks, so it runs from the Monday on or before the
        // 1st to the Sunday on or after the last day.
        $gridStart = $month->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $events = WotEvent::with('article:id,title,url')
            ->onlyBetween($gridStart, $gridEnd)
            ->orderBy('starts_at')
            ->get();

        // Long campaigns are pulled out of the grid. A Battle Pass season runs
        // for three months, and repeating it in all 35 squares buried the
        // individual stream sessions that are the reason to look at a calendar
        // at all. They appear once, above the grid, still with their dates.
        [$ongoing, $dated] = $events->partition(fn (WotEvent $event): bool => $this->isLongRunning($event));

        return Inertia::render('Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'previousMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'days' => $this->days($gridStart, $gridEnd, $month, $dated),
            'ongoing' => $ongoing->map(fn (WotEvent $event): array => $this->event($event, $gridStart))->values()->all(),
            'upcoming' => $this->upcoming(),
        ]);
    }

    /**
     * Spans longer than a week, which would otherwise occupy every square.
     *
     * Only ever true of window events: a calendar session is a single sitting,
     * so a long one would mean the extraction misread something.
     */
    private function isLongRunning(WotEvent $event): bool
    {
        return $event->ends_at !== null
            && $event->starts_at->diffInDays($event->ends_at) > 7;
    }

    /**
     * @param  Collection<int, WotEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function days(Carbon $start, Carbon $end, Carbon $month, Collection $events): array
    {
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $onThisDay = $events->filter(function (WotEvent $event) use ($dayStart, $dayEnd): bool {
                $eventEnd = $event->ends_at ?? $event->starts_at;

                return $event->starts_at->lte($dayEnd) && $eventEnd->gte($dayStart);
            });

            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'in_month' => $date->month === $month->month,
                'is_today' => $date->isToday(),
                'events' => $onThisDay->map(fn (WotEvent $event): array => $this->event($event, $dayStart))->values()->all(),
            ];
        }

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(WotEvent $event, Carbon $day): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'type' => $event->event_type,
            'source' => $event->source,
            // Only a session that actually starts on this day shows a time; a
            // multi-day window rendered with "16:00" on every square would be
            // stating something untrue.
            'time' => $event->source === WotEvent::SOURCE_CALENDAR && $event->starts_at->isSameDay($day)
                ? $event->starts_at->format('H:i')
                : null,
            'ends_time' => $event->source === WotEvent::SOURCE_CALENDAR && $event->ends_at?->isSameDay($day)
                ? $event->ends_at->format('H:i')
                : null,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'metadata' => $event->metadata,
            'article' => ['title' => $event->article?->title, 'url' => $event->article?->url],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(): array
    {
        return WotEvent::with('article:id,title,url')
            ->onlyUpcoming()
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (WotEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'type' => $event->event_type,
                'source' => $event->source,
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'article' => ['title' => $event->article?->title, 'url' => $event->article?->url],
            ])
            ->all();
    }
}
