<?php

namespace App\Services\WotNews;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Models\User;
use App\Models\WotEvent;

/**
 * The month calendar: its grid of days, and the long campaigns listed above it.
 *
 * Days are assembled server-side rather than in Vue because a window event
 * spans many days and has to appear on each of them. Resolving that once here
 * is simpler than teaching the calendar component to expand ranges, and it
 * keeps the payload to exactly what is rendered.
 *
 * A multi-day event is listed on every day it covers, for the day view, but
 * drawn as one bar per week row — see spans().
 */
class CalendarBoard
{
    /**
     * @return array{days: list<array<string, mixed>>, spans: list<array<string, mixed>>, ongoing: list<array<string, mixed>>}
     */
    public function for(Carbon $month, ?User $user): array
    {
        // The grid is whole weeks, Sunday to Saturday, because MonthGrid.vue's
        // labels are fixed that way. Named rather than left to the locale:
        // Carbon starts the week on Monday under `en` and on Sunday under
        // `en_US`, and production ran with the first while development ran
        // with the second — every date sat one column left of its weekday.
        $gridStart = $month->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        // Ignored events are gone from the grid and the long-campaign list
        // below — the whole point of ignoring one. They stay in "Coming up",
        // which is where they are reconsidered.
        $events = WotEvent::with('article:id,title,url')
            ->notIgnoredBy($user)
            ->onlyBetween($gridStart, $gridEnd)
            ->inDefaultOrder()
            ->get();

        // Long campaigns are pulled out of the grid. A Battle Pass season runs
        // for three months, and repeating it in all 35 squares buried the
        // individual stream sessions that are the reason to look at a calendar
        // at all. They appear once, above the grid, still with their dates.
        [$ongoing, $dated] = $events->partition(fn (WotEvent $event): bool => $event->is_long_running);

        $days = [];

        for ($date = $gridStart->copy(); $date->lte($gridEnd); $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'in_month' => $date->month === $month->month,
                'is_today' => $date->isToday(),
                'events' => $dated
                    ->filter(fn (WotEvent $event): bool => $event->occursOn($date))
                    ->map(fn (WotEvent $event): array => [
                        ...$event->list_item_props,
                        'metadata' => $event->metadata,
                        'time' => $event->startTimeOn($date),
                        'ends_time' => $event->endTimeOn($date),
                        'is_final_day' => $event->isFinalDayOn($date),
                        'spans_days' => $event->spans_days,
                    ])
                    ->values()
                    ->all(),
                // The long campaigns covering this day. Ids only: they are
                // already in the `ongoing` list, and repeating each one in
                // every square it spans is the duplication that keeps them out
                // of the grid to begin with. The day view looks them up.
                'ongoing_ids' => $ongoing
                    ->filter(fn (WotEvent $event): bool => $event->occursOn($date))
                    ->pluck('id')
                    ->values()
                    ->all(),
            ];
        }

        return [
            'days' => $days,
            'spans' => $this->spans($dated->filter(fn (WotEvent $event): bool => $event->spans_days), $gridStart, $gridEnd),
            'ongoing' => $ongoing
                ->map(fn (WotEvent $event): array => [
                    ...$event->list_item_props,
                    'metadata' => $event->metadata,
                    'time' => null,
                    'ends_time' => null,
                    'is_final_day' => false,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The bars drawn across the grid for events that run over several days —
     * one per week row an event touches, so its label appears once per line
     * rather than in every square.
     *
     * Each week stacks its bars in lanes: a bar takes the first lane that is
     * free from its first column on. The events arrive soonest first, so
     * within a week the bars are placed left to right and the greedy choice
     * never uses more lanes than the busiest day needs.
     *
     * Columns come from the day of the week rather than a difference in days,
     * which a short day at a DST change would round down by one.
     *
     * @param  Collection<int, WotEvent>  $events
     * @return list<array{event: array<string, mixed>, week: int, column: int, length: int, lane: int, continues_before: bool, continues_after: bool, time: ?string, is_final_day: bool}>
     */
    private function spans(Collection $events, Carbon $gridStart, Carbon $gridEnd): array
    {
        $spans = [];

        for ($weekStart = $gridStart->copy(), $week = 0; $weekStart->lte($gridEnd); $weekStart->addWeek(), $week++) {
            $weekLast = $weekStart->copy()->addDays(6);

            /** @var array<int, int> $laneEnds the last column taken in each lane */
            $laneEnds = [];

            foreach ($events as $event) {
                $firstDay = $event->starts_at->copy()->startOfDay();
                $lastDay = $event->ends_at->copy()->startOfDay();
                $from = $firstDay->max($weekStart);
                $to = $lastDay->min($weekLast);

                if ($from->gt($to)) {
                    continue;
                }

                $column = $from->dayOfWeek + 1;
                $length = $to->dayOfWeek - $from->dayOfWeek + 1;

                $lane = 0;
                while (isset($laneEnds[$lane]) && $laneEnds[$lane] >= $column) {
                    $lane++;
                }
                $laneEnds[$lane] = $column + $length - 1;

                $spans[] = [
                    // The event as a whole, for the dialog a bar opens — so
                    // not marked as a final day, which a bar is not.
                    'event' => [
                        ...$event->list_item_props,
                        'metadata' => $event->metadata,
                        'time' => null,
                        'ends_time' => null,
                        'is_final_day' => false,
                    ],
                    'week' => $week,
                    'column' => $column,
                    'length' => $length,
                    'lane' => $lane,
                    'continues_before' => $from->gt($firstDay),
                    'continues_after' => $to->lt($lastDay),
                    'time' => $event->startTimeOn($from),
                    'is_final_day' => $event->isFinalDayOn($to),
                ];
            }
        }

        return $spans;
    }
}
