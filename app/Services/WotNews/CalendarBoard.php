<?php

namespace App\Services\WotNews;

use Illuminate\Support\Carbon;
use App\Models\User;
use App\Models\WotEvent;

/**
 * The month calendar: its grid of days, and the long campaigns listed above it.
 *
 * Days are assembled server-side rather than in Vue because a window event
 * spans many days and has to appear on each of them. Resolving that once here
 * is simpler than teaching the calendar component to expand ranges, and it
 * keeps the payload to exactly what is rendered.
 */
class CalendarBoard
{
    /**
     * @return array{days: list<array<string, mixed>>, ongoing: list<array<string, mixed>>}
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
}
