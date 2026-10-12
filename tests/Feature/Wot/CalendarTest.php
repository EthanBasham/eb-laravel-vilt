<?php

use Illuminate\Testing\TestResponse;
use App\Models\User;
use App\Models\WotEvent;

/*
 * Every fixture here is pinned to September 2026, because the grid's shape is
 * part of what is asserted: it runs whole weeks from the Sunday on or before
 * the 1st, so which square a date lands in depends on the weekday the month
 * opens with. Relative dates would move that from one run to the next.
 *
 * Fixed dates alone are not enough, though. "Coming up" is `onlyUpcoming()`,
 * which is measured against now() — so the moment the real clock passed
 * 2026-09-15 the events below stopped being upcoming and the listing emptied.
 * The clock is frozen to the start of the month so the two agree: the dates are
 * fixed, and so is the today they are ahead of.
 */
beforeEach(fn () => test()->travelTo('2026-09-01 08:00:00'));

/**
 * The events the month grid puts on one date.
 *
 * Found by date rather than by index: the grid runs whole weeks from the Sunday
 * on or before the 1st, so a square's position depends on which weekday the
 * month opens with.
 *
 * @return list<array<string, mixed>>
 */
function eventsOn(TestResponse $response, string $date): array
{
    $days = collect($response->viewData('page')['props']['days']);

    return $days->firstWhere('date', $date)['events'];
}

function september(User $user): TestResponse
{
    return test()->actingAs($user)->get(route('wot.calendar', ['month' => '2026-09']));
}

it('lays the month out in whole weeks from Sunday to Saturday', function () {
    $days = collect(september(User::factory()->create())->viewData('page')['props']['days']);

    // September 2026 opens on a Tuesday and closes on a Wednesday.
    expect($days->first()['date'])->toBe('2026-08-30')
        ->and($days->last()['date'])->toBe('2026-10-03')
        ->and($days)->toHaveCount(35);
});

/**
 * Carbon takes the first day of the week from the locale: Monday under `en`,
 * Sunday under `en_US`. Production ran with `en` and development with `en_US`,
 * so the same code put every date one column left of its weekday in production
 * only. The column labels are fixed, so the grid must be too.
 */
it('starts the week on Sunday whatever the locale', function (string $locale) {
    app()->setLocale($locale);

    $days = collect(september(User::factory()->create())->viewData('page')['props']['days']);

    expect($days->first()['date'])->toBe('2026-08-30')
        ->and($days->last()['date'])->toBe('2026-10-03');
})->with(['en', 'en_US', 'en_GB']);

/**
 * On the 31st, parsing "2026-09" with the day filled in from today gave
 * September 31st, which rolled over to October.
 */
it('shows the requested month when today is later than that month has days', function () {
    $this->travelTo('2026-08-31 10:00:00');

    $this->actingAs(User::factory()->create())
        ->get(route('wot.calendar', ['month' => '2026-09']))
        ->assertInertia(fn ($page) => $page->where('month', '2026-09'));
});

it('falls back to the current month for a month it cannot read', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('wot.calendar', ['month' => 'not-a-month']))
        ->assertInertia(fn ($page) => $page->where('month', '2026-09'));
});

it('marks only the last square of a run that spans days', function () {
    $user = User::factory()->create();

    WotEvent::factory()->create([
        'title' => 'Trade In and Roll Out',
        'starts_at' => '2026-09-04 04:00:00',
        'ends_at' => '2026-09-11 04:00:00',
    ]);

    $response = september($user);

    expect(eventsOn($response, '2026-09-04')[0]['is_final_day'])->toBeFalse()
        ->and(eventsOn($response, '2026-09-07')[0]['is_final_day'])->toBeFalse()
        ->and(eventsOn($response, '2026-09-11')[0]['is_final_day'])->toBeTrue();
});

/**
 * Otherwise every stream session would announce itself as ending, which tells
 * the reader nothing they can act on.
 */
it('leaves a single-day session unmarked', function () {
    $user = User::factory()->create();

    WotEvent::factory()->calendar()->create([
        'title' => 'AMD OLS#7 Phase 1 Day 4',
        'starts_at' => '2026-09-15 11:00:00',
        'ends_at' => '2026-09-15 17:59:00',
    ]);

    expect(eventsOn(september($user), '2026-09-15')[0]['is_final_day'])->toBeFalse();
});

it('requires auth to ignore', function () {
    $event = WotEvent::factory()->create();

    $this->post(route('wot.calendar.events.ignore', $event))->assertRedirect(route('login'));
    $this->delete(route('wot.calendar.events.unignore', $event))->assertRedirect(route('login'));
});

it('re-ignoring is idempotent', function () {
    $user = User::factory()->create();
    $event = WotEvent::factory()->calendar()->create();

    $this->actingAs($user)->post(route('wot.calendar.events.ignore', $event));
    $this->travel(1)->minutes();
    $this->actingAs($user)->post(route('wot.calendar.events.ignore', $event))->assertRedirect();

    $this->assertDatabaseCount('wot_event_ignores', 1);
});

it('drops an ignored event from the grid but keeps it in coming up', function () {
    $user = User::factory()->create();

    $event = WotEvent::factory()->calendar()->create([
        'title' => 'Stream nobody wants',
        'starts_at' => '2026-09-15 11:00:00',
        'ends_at' => '2026-09-15 17:59:00',
    ]);

    $this->actingAs($user)->post(route('wot.calendar.events.ignore', $event))->assertRedirect();

    $props = september($user)->viewData('page')['props'];

    expect(collect($props['days'])->firstWhere('date', '2026-09-15')['events'])->toBeEmpty()
        ->and($props['upcomingEvents'])->toHaveCount(1)
        ->and($props['upcomingEvents'][0]['is_ignored'])->toBeTrue();
});

it('drops an ignored campaign from running all month', function () {
    $user = User::factory()->create();

    $campaign = WotEvent::factory()->create([
        'title' => 'Battle Pass Season XXI',
        'starts_at' => '2026-09-05 04:00:00',
        'ends_at' => '2026-11-24 01:30:00',
    ]);

    $this->actingAs($user)->post(route('wot.calendar.events.ignore', $campaign))->assertRedirect();

    $props = september($user)->viewData('page')['props'];

    expect($props['ongoingEvents'])->toBeEmpty()
        // And so gone from the day view, which reads the same ids.
        ->and(collect($props['days'])->firstWhere('date', '2026-09-10')['ongoing_ids'])->toBe([]);
});

it('restores an event on reconsidering it', function () {
    $user = User::factory()->create();

    $event = WotEvent::factory()->calendar()->create([
        'starts_at' => '2026-09-15 11:00:00',
        'ends_at' => '2026-09-15 17:59:00',
    ]);

    $this->actingAs($user)->post(route('wot.calendar.events.ignore', $event));
    $this->actingAs($user)->delete(route('wot.calendar.events.unignore', $event))->assertRedirect();

    $props = september($user)->viewData('page')['props'];

    expect(collect($props['days'])->firstWhere('date', '2026-09-15')['events'])->toHaveCount(1)
        ->and($props['upcomingEvents'][0]['is_ignored'])->toBeFalse();
});

/**
 * Events are shared rows synced from Wargaming, so one person's decision to
 * stop seeing something must not reach into anybody else's calendar.
 */
it('keeps one user\'s ignores out of another\'s calendar', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $event = WotEvent::factory()->calendar()->create([
        'starts_at' => '2026-09-15 11:00:00',
        'ends_at' => '2026-09-15 17:59:00',
    ]);

    $this->actingAs($user)->post(route('wot.calendar.events.ignore', $event));

    $days = collect(september($other)->viewData('page')['props']['days']);

    expect($days->firstWhere('date', '2026-09-15')['events'])->toHaveCount(1);
});

/**
 * The day view lists what is running on a square, which includes the long
 * campaigns the grid itself leaves out. Ids rather than copies: the campaigns
 * already travel once in the `ongoingEvents` prop.
 */
it('tells each day which long campaigns cover it', function () {
    $user = User::factory()->create();

    $campaign = WotEvent::factory()->create([
        'title' => 'Battle Pass Season XXI',
        'starts_at' => '2026-09-05 04:00:00',
        'ends_at' => '2026-11-24 01:30:00',
    ]);

    $days = collect(september($user)->viewData('page')['props']['days']);

    expect($days->firstWhere('date', '2026-09-04')['ongoing_ids'])->toBe([])
        ->and($days->firstWhere('date', '2026-09-05')['ongoing_ids'])->toBe([$campaign->id])
        ->and($days->firstWhere('date', '2026-09-30')['ongoing_ids'])->toBe([$campaign->id])
        // Still kept out of the squares themselves.
        ->and($days->firstWhere('date', '2026-09-30')['events'])->toBeEmpty();
});

it('lists only events longer than a week above the grid', function (string $endsAt, int $aboveGrid, int $inSquare) {
    WotEvent::factory()->create([
        'starts_at' => '2026-09-08 04:00:00',
        'ends_at' => $endsAt,
    ]);

    $response = september(User::factory()->create());

    expect($response->viewData('page')['props']['ongoingEvents'])->toHaveCount($aboveGrid)
        ->and(eventsOn($response, '2026-09-08'))->toHaveCount($inSquare);
})->with([
    'exactly a week stays in the grid' => ['2026-09-15 04:00:00', 0, 1],
    'a week and a minute goes above it' => ['2026-09-15 04:01:00', 1, 0],
]);

/**
 * A campaign longer than a week is listed above the grid instead of filling
 * every square, so it has no last square to mark.
 */
it('leaves a long campaign unmarked', function () {
    $user = User::factory()->create();

    WotEvent::factory()->create([
        'title' => 'Under the Sign of Syrenka',
        'starts_at' => '2026-09-01 04:00:00',
        'ends_at' => '2026-09-30 04:00:00',
    ]);

    $response = september($user);
    $ongoingEvents = $response->viewData('page')['props']['ongoingEvents'];

    expect($ongoingEvents)->toHaveCount(1)
        ->and($ongoingEvents[0])->not->toHaveKey('is_final_day')
        ->and(eventsOn($response, '2026-09-30'))->toBeEmpty();
});

/**
 * A multi-day event is drawn as a bar through the squares it covers, one per
 * week row, so its label is written once per line rather than once per day.
 */
it('draws a multi-day event as one bar per week it touches', function () {
    // Thursday the 10th to Tuesday the 15th: the third and fourth week rows.
    $event = WotEvent::factory()->create([
        'starts_at' => '2026-09-10 04:00:00',
        'ends_at' => '2026-09-15 04:00:00',
    ]);

    $spans = september(User::factory()->create())->viewData('page')['props']['spans'];

    expect($spans)->toHaveCount(2)
        ->and(collect($spans)->pluck('event.id')->unique()->all())->toBe([$event->id])
        ->and(collect($spans[0])->only(['week', 'column', 'length', 'continues_before', 'continues_after'])->all())
        ->toBe(['week' => 1, 'column' => 5, 'length' => 3, 'continues_before' => false, 'continues_after' => true])
        ->and(collect($spans[1])->only(['week', 'column', 'length', 'continues_before', 'continues_after'])->all())
        ->toBe(['week' => 2, 'column' => 1, 'length' => 3, 'continues_before' => true, 'continues_after' => false]);
});

it('stacks overlapping bars in lanes and reuses a lane once it is free', function () {
    $first = WotEvent::factory()->create(['starts_at' => '2026-09-06 04:00:00', 'ends_at' => '2026-09-08 04:00:00']);
    $overlapping = WotEvent::factory()->create(['starts_at' => '2026-09-07 04:00:00', 'ends_at' => '2026-09-09 04:00:00']);
    $after = WotEvent::factory()->create(['starts_at' => '2026-09-10 04:00:00', 'ends_at' => '2026-09-11 04:00:00']);

    $lanes = collect(september(User::factory()->create())->viewData('page')['props']['spans'])
        ->mapWithKeys(fn (array $span): array => [$span['event']['id'] => $span['lane']]);

    expect($lanes->all())->toBe([$first->id => 0, $overlapping->id => 1, $after->id => 0]);
});

/**
 * The bar is drawn by the grid, but the day view still lists the event on
 * every day it covers — flagged, so the square does not draw it a second time.
 */
it('keeps a multi-day event on each of its days, flagged as spanning', function () {
    WotEvent::factory()->create(['starts_at' => '2026-09-10 04:00:00', 'ends_at' => '2026-09-11 04:00:00']);
    WotEvent::factory()->calendar()->create(['starts_at' => '2026-09-10 11:00:00', 'ends_at' => '2026-09-10 17:59:00']);

    $response = september(User::factory()->create());

    expect(collect(eventsOn($response, '2026-09-10'))->pluck('spans_days')->all())->toBe([true, false])
        ->and(eventsOn($response, '2026-09-11'))->toHaveCount(1)
        ->and($response->viewData('page')['props']['spans'])->toHaveCount(1);
});
