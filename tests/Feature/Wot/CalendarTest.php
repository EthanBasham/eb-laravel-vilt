<?php

use Illuminate\Testing\TestResponse;
use App\Models\User;
use App\Models\WotEvent;

/**
 * The events the month grid puts on one date.
 *
 * Found by date rather than by index: the grid runs whole weeks from the Monday
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

    $this->post(route('wot.events.ignore', $event))->assertRedirect(route('login'));
    $this->delete(route('wot.events.unignore', $event))->assertRedirect(route('login'));
});

it('drops an ignored event from the grid but keeps it in coming up', function () {
    $user = User::factory()->create();

    $event = WotEvent::factory()->calendar()->create([
        'title' => 'Stream nobody wants',
        'starts_at' => '2026-09-15 11:00:00',
        'ends_at' => '2026-09-15 17:59:00',
    ]);

    $this->actingAs($user)->post(route('wot.events.ignore', $event))->assertRedirect();

    $props = september($user)->viewData('page')['props'];

    expect(collect($props['days'])->firstWhere('date', '2026-09-15')['events'])->toBeEmpty()
        ->and($props['upcoming'])->toHaveCount(1)
        ->and($props['upcoming'][0]['is_ignored'])->toBeTrue();
});

it('drops an ignored campaign from running all month', function () {
    $user = User::factory()->create();

    $campaign = WotEvent::factory()->create([
        'title' => 'Battle Pass Season XXI',
        'starts_at' => '2026-09-05 04:00:00',
        'ends_at' => '2026-11-24 01:30:00',
    ]);

    $this->actingAs($user)->post(route('wot.events.ignore', $campaign))->assertRedirect();

    $props = september($user)->viewData('page')['props'];

    expect($props['ongoing'])->toBeEmpty()
        // And so gone from the day view, which reads the same ids.
        ->and(collect($props['days'])->firstWhere('date', '2026-09-10')['ongoing_ids'])->toBe([]);
});

it('restores an event on reconsidering it', function () {
    $user = User::factory()->create();

    $event = WotEvent::factory()->calendar()->create([
        'starts_at' => '2026-09-15 11:00:00',
        'ends_at' => '2026-09-15 17:59:00',
    ]);

    $this->actingAs($user)->post(route('wot.events.ignore', $event));
    $this->actingAs($user)->delete(route('wot.events.unignore', $event))->assertRedirect();

    $props = september($user)->viewData('page')['props'];

    expect(collect($props['days'])->firstWhere('date', '2026-09-15')['events'])->toHaveCount(1)
        ->and($props['upcoming'][0]['is_ignored'])->toBeFalse();
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

    $this->actingAs($user)->post(route('wot.events.ignore', $event));

    $days = collect(september($other)->viewData('page')['props']['days']);

    expect($days->firstWhere('date', '2026-09-15')['events'])->toHaveCount(1);
});

/**
 * The day view lists what is running on a square, which includes the long
 * campaigns the grid itself leaves out. Ids rather than copies: the campaigns
 * already travel once in the `ongoing` prop.
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
    $ongoing = $response->viewData('page')['props']['ongoing'];

    expect($ongoing)->toHaveCount(1)
        ->and($ongoing[0]['is_final_day'])->toBeFalse()
        ->and(eventsOn($response, '2026-09-30'))->toBeEmpty();
});
