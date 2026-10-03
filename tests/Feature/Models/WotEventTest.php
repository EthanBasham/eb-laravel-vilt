<?php

use Illuminate\Support\Carbon;
use App\Models\WotArticle;
use App\Models\WotEvent;

it('builds the fields every event listing shares', function () {
    $article = WotArticle::factory()->create(['title' => 'Token Store', 'url' => 'https://worldoftanks.com/en/news/token-store/']);
    $event = WotEvent::factory()->calendar()->for($article, 'article')->create([
        'title' => 'Community Stream',
        'starts_at' => '2026-09-10 16:00:00',
        'ends_at' => null,
    ]);

    expect($event->list_item_props)->toBe([
        'id' => $event->id,
        'title' => 'Community Stream',
        'type' => 'stream',
        'source' => WotEvent::SOURCE_CALENDAR,
        'starts_at' => Carbon::parse('2026-09-10 16:00:00')->toIso8601String(),
        'ends_at' => null,
        'article' => ['title' => 'Token Store', 'url' => 'https://worldoftanks.com/en/news/token-store/'],
    ]);
});

it('shows a session\'s times only on the day each falls', function () {
    $event = WotEvent::factory()->calendar()->make([
        'starts_at' => '2026-09-10 22:00:00',
        'ends_at' => '2026-09-11 01:30:00',
    ]);

    expect($event->startTimeOn(Carbon::parse('2026-09-10')))->toBe('22:00')
        ->and($event->endTimeOn(Carbon::parse('2026-09-10')))->toBeNull()
        ->and($event->startTimeOn(Carbon::parse('2026-09-11')))->toBeNull()
        ->and($event->endTimeOn(Carbon::parse('2026-09-11')))->toBe('01:30');
});

it('shows no times for a window, even on the days it starts and ends', function () {
    $window = WotEvent::factory()->make([
        'starts_at' => '2026-09-10 04:00:00',
        'ends_at' => '2026-09-14 04:00:00',
    ]);

    expect($window->startTimeOn(Carbon::parse('2026-09-10')))->toBeNull()
        ->and($window->endTimeOn(Carbon::parse('2026-09-14')))->toBeNull();
});

it('marks the final day only of a run that spans days', function () {
    $run = WotEvent::factory()->make(['starts_at' => '2026-09-10 04:00:00', 'ends_at' => '2026-09-14 04:00:00']);
    $sitting = WotEvent::factory()->calendar()->make(['starts_at' => '2026-09-10 16:00:00', 'ends_at' => '2026-09-10 22:59:00']);

    expect($run->isFinalDayOn(Carbon::parse('2026-09-14')))->toBeTrue()
        ->and($run->isFinalDayOn(Carbon::parse('2026-09-13')))->toBeFalse()
        ->and($sitting->isFinalDayOn(Carbon::parse('2026-09-10')))->toBeFalse();
});

it('orders soonest first', function () {
    WotEvent::factory()->count(3)->sequence(
        ['title' => 'Last', 'starts_at' => '2026-09-12 16:00:00'],
        ['title' => 'First', 'starts_at' => '2026-09-10 09:00:00'],
        ['title' => 'Middle', 'starts_at' => '2026-09-11 16:00:00'],
    )->create();

    expect(WotEvent::inDefaultOrder()->pluck('title')->all())->toBe(['First', 'Middle', 'Last']);
});

it('occurs on every day its span touches', function () {
    $run = WotEvent::factory()->make(['starts_at' => '2026-09-10 22:00:00', 'ends_at' => '2026-09-12 01:00:00']);

    expect($run->occursOn(Carbon::parse('2026-09-09')))->toBeFalse()
        ->and($run->occursOn(Carbon::parse('2026-09-10')))->toBeTrue()
        ->and($run->occursOn(Carbon::parse('2026-09-11')))->toBeTrue()
        ->and($run->occursOn(Carbon::parse('2026-09-12')))->toBeTrue()
        ->and($run->occursOn(Carbon::parse('2026-09-13')))->toBeFalse();
});

it('occurs only on its start day when it has no end', function () {
    $moment = WotEvent::factory()->calendar()->make(['starts_at' => '2026-09-10 16:00:00', 'ends_at' => null]);

    expect($moment->occursOn(Carbon::parse('2026-09-10')))->toBeTrue()
        ->and($moment->occursOn(Carbon::parse('2026-09-11')))->toBeFalse();
});
