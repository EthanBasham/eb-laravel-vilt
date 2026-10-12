<?php

namespace App\Services\WotNews;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Models\User;
use App\Models\WotEvent;

/**
 * The month calendar: its grid of days, the long campaigns listed above it,
 * and the "Coming up" list below it.
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
     * @return array{days: list<array<string, mixed>>, spans: list<array<string, mixed>>, ongoingEvents: list<array<string, mixed>>, upcomingEvents: list<array<string, mixed>>}
     */
    public function for(Carbon $month, ?User $user): array
    {
        $gridStart = $month->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        // All unignored events for the grid and the "Running All Month" list (bounded by month)
        $events = WotEvent::with('article:id,title,url')
            ->notIgnoredBy($user)
            ->onlyBetween($gridStart, $gridEnd)
            ->inDefaultOrder()
            ->get();

        // Split long-running "Running All Month" events from short-lived grid events.
        [$ongoingEvents, $gridEvents] = $events->partition(fn (WotEvent $event): bool => $event->is_long_running);

        $days = [];

        for ($date = $gridStart->copy(); $date->lte($gridEnd); $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'in_month' => $date->month === $month->month,
                'is_today' => $date->isToday(),
                'events' => $gridEvents
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

                // IDs of "Running All Month" events for this day. Looked up and listed in the day dialog
                'ongoing_ids' => $ongoingEvents
                    ->filter(fn (WotEvent $event): bool => $event->occursOn($date))
                    ->pluck('id')
                    ->values()
                    ->all(),
            ];
        }

        return [
            'days' => $days,
            'spans' => $this->spans($gridEvents->filter(fn (WotEvent $event): bool => $event->spans_days), $gridStart, $gridEnd),
            'ongoingEvents' => $ongoingEvents
                ->map(fn (WotEvent $event): array => [
                    ...$event->list_item_props,
                    'metadata' => $event->metadata,
                    'time' => null,
                    'ends_time' => null,
                ])
                ->values()
                ->all(),

            // All events that have yet to start or end (unbounded by month)
            'upcomingEvents' => WotEvent::with('article:id,title,url')
                ->withIgnoredFor($user)
                ->onlyUpcoming()
                ->inDefaultOrder()
                ->limit(10)
                ->get()
                ->map(fn (WotEvent $event): array => [
                    ...$event->list_item_props,
                    'is_ignored' => $event->ignored_at !== null,
                ])
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
     * @return list<array{event: array<string, mixed>, week: int, column: int, length: int, lane: int, continues_before: bool, continues_after: bool, time: ?string}>
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
                    'event' => [
                        ...$event->list_item_props,
                        'metadata' => $event->metadata,
                        'time' => null,
                        'ends_time' => null,
                    ],
                    'week' => $week,
                    'column' => $column,
                    'length' => $length,
                    'lane' => $lane,
                    'continues_before' => $from->gt($firstDay),
                    'continues_after' => $to->lt($lastDay),
                    'time' => $event->startTimeOn($from),
                ];
            }
        }

        return $spans;
    }
}
