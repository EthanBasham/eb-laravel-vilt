<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WotEvent;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The month calendar and the per-user ignoring behind it.
 *
 * Split from NewsController on 2026-09-23. The two share a source — events are
 * extracted from article bodies by wot:sync-news — but nothing else: this reads
 * wot_events and wot_event_ignores and renders a grid, and none of its five
 * helpers were ever reachable from a news route. Resyncing both still belongs
 * to NewsController, since that is the button it hangs off.
 */
class CalendarController extends Controller
{
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

        // Ignored events are gone from the grid and the long-campaign list
        // below — the whole point of ignoring one. They stay in "Coming up",
        // which is where they are reconsidered.
        $events = WotEvent::with('article:id,title,url')
            ->notIgnoredBy($request->user())
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
            'days' => $this->days($gridStart, $gridEnd, $month, $dated, $ongoing),
            'ongoing' => $ongoing->map(fn (WotEvent $event): array => $this->event($event))->values()->all(),
            'upcoming' => $this->upcoming($request->user()),
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
     * @param  Collection<int, WotEvent>  $ongoing
     * @return list<array<string, mixed>>
     */
    private function days(Carbon $start, Carbon $end, Carbon $month, Collection $events, Collection $ongoing): array
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
                // The long campaigns covering this day. Ids only: they are
                // already in the `ongoing` prop, and repeating each one in
                // every square it spans is the duplication that keeps them out
                // of the grid to begin with. The day view looks them up.
                'ongoing_ids' => $ongoing
                    ->filter(fn (WotEvent $event): bool => $event->starts_at->lte($dayEnd)
                        && ($event->ends_at ?? $event->starts_at)->gte($dayStart))
                    ->pluck('id')
                    ->values()
                    ->all(),
            ];
        }

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(WotEvent $event, ?Carbon $day = null): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'type' => $event->event_type,
            'source' => $event->source,
            // Only a session that actually starts on this day shows a time; a
            // multi-day window rendered with "16:00" on every square would be
            // stating something untrue.
            'time' => $day && $event->source === WotEvent::SOURCE_CALENDAR && $event->starts_at->isSameDay($day)
                ? $event->starts_at->format('H:i')
                : null,
            'ends_time' => $day && $event->source === WotEvent::SOURCE_CALENDAR && $event->ends_at?->isSameDay($day)
                ? $event->ends_at->format('H:i')
                : null,
            // The last square of a run that actually spans days. A single
            // sitting is excluded deliberately: every one-day event would
            // otherwise announce itself as ending, which says nothing.
            //
            // False without a day, which is the long-campaign list above the
            // grid: those occupy no square, so "which square is the last one"
            // has no answer there and a flag computed against the grid's first
            // day would fire on an arbitrary one.
            'is_final_day' => $day !== null
                && $event->ends_at !== null
                && $event->ends_at->isSameDay($day)
                && ! $event->starts_at->isSameDay($event->ends_at),
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'metadata' => $event->metadata,
            'article' => ['title' => $event->article?->title, 'url' => $event->article?->url],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(?User $user): array
    {
        // Deliberately not filtered by notIgnoredBy(): this listing is the only
        // place an ignored event can be reconsidered, so hiding it here would
        // make the decision irreversible.
        return WotEvent::with('article:id,title,url')
            ->withIgnoredFor($user)
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
                'is_ignored' => $event->ignored_at !== null,
                'article' => ['title' => $event->article?->title, 'url' => $event->article?->url],
            ])
            ->all();
    }

    /**
     * Hide an event from this user's schedule views.
     *
     * Idempotent, like pinning: ignoring something already ignored refreshes
     * the timestamp rather than failing on the unique constraint.
     */
    public function ignore(Request $request, WotEvent $event): RedirectResponse
    {
        $request->user()->ignoredEvents()->syncWithoutDetaching([
            $event->id => ['ignored_at' => now()],
        ]);

        // No flash: the event leaves the grid and its row gains the muted
        // styling, which reports the outcome more directly than a banner.
        return back(fallback: route('wot.calendar'));
    }

    public function unignore(Request $request, WotEvent $event): RedirectResponse
    {
        $request->user()->ignoredEvents()->detach($event->id);

        return back(fallback: route('wot.calendar'));
    }
}
