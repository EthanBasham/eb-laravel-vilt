<?php

use Illuminate\Support\Carbon;
use App\Models\WotArticle;
use App\Models\WotEvent;

/**
 * An instant given in UTC and carried in app time, the way EventExtractor
 * hands dates over.
 */
function utcInstant(string $time): Carbon
{
    return Carbon::parse($time, 'UTC')->setTimezone(config('app.timezone'));
}

/**
 * One row of EventExtractor::extract() output.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function extractedEvent(array $attributes = []): array
{
    return [
        'title' => 'Community Stream',
        'starts_at' => utcInstant('2026-10-05 16:00'),
        'ends_at' => null,
        'event_type' => 'stream',
        'source' => WotEvent::SOURCE_CALENDAR,
        'metadata' => null,
        ...$attributes,
    ];
}

it('keeps an unchanged event and refreshes its details', function () {
    $article = WotArticle::factory()->create();
    $event = WotEvent::factory()->calendar()->for($article, 'article')->create([
        'title' => 'Community Stream',
        'starts_at' => utcInstant('2026-10-05 16:00'),
        'ends_at' => null,
    ]);

    $changes = $article->syncEvents([extractedEvent(['metadata' => ['tokens' => '5']])]);

    expect($changes)->toBe(['kept' => 1, 'moved' => 0, 'renamed' => 0, 'created' => 0, 'removed' => 0])
        ->and($article->events()->sole()->is($event))->toBeTrue()
        ->and($event->fresh()->metadata)->toBe(['tokens' => '5']);
});

it('moves a rescheduled event rather than replacing it', function () {
    $article = WotArticle::factory()->create();
    $event = WotEvent::factory()->calendar()->for($article, 'article')->create([
        'title' => 'Community Stream',
        'starts_at' => utcInstant('2026-10-05 16:00'),
    ]);

    $changes = $article->syncEvents([extractedEvent(['starts_at' => utcInstant('2026-10-06 18:00')])]);

    expect($changes)->toBe(['kept' => 0, 'moved' => 1, 'renamed' => 0, 'created' => 0, 'removed' => 0])
        ->and($article->events()->sole()->is($event))->toBeTrue()
        ->and($event->fresh()->starts_at->utc()->toDateTimeString())->toBe('2026-10-06 18:00:00');
});

it('renames an event whose title changed at the same start', function () {
    $article = WotArticle::factory()->create();
    $event = WotEvent::factory()->calendar()->for($article, 'article')->create([
        'title' => 'Comunity Stream',
        'starts_at' => utcInstant('2026-10-05 16:00'),
    ]);

    $changes = $article->syncEvents([extractedEvent(['title' => 'Community Stream'])]);

    expect($changes)->toBe(['kept' => 0, 'moved' => 0, 'renamed' => 1, 'created' => 0, 'removed' => 0])
        ->and($article->events()->sole()->is($event))->toBeTrue()
        ->and($event->fresh()->title)->toBe('Community Stream');
});

it('pairs repeated titles in date order when they all shift', function () {
    $article = WotArticle::factory()->create();
    $events = WotEvent::factory()->calendar()->for($article, 'article')->count(3)->sequence(
        ['title' => 'Community Stream', 'starts_at' => utcInstant('2026-10-05 16:00')],
        ['title' => 'Community Stream', 'starts_at' => utcInstant('2026-10-07 16:00')],
        ['title' => 'Community Stream', 'starts_at' => utcInstant('2026-10-09 16:00')],
    )->create();

    $changes = $article->syncEvents([
        extractedEvent(['starts_at' => utcInstant('2026-10-10 16:00')]),
        extractedEvent(['starts_at' => utcInstant('2026-10-06 16:00')]),
        extractedEvent(['starts_at' => utcInstant('2026-10-08 16:00')]),
    ]);

    expect($changes)->toBe(['kept' => 0, 'moved' => 3, 'renamed' => 0, 'created' => 0, 'removed' => 0])
        ->and($events->map(fn (WotEvent $event): string => $event->fresh()->starts_at->utc()->toDateTimeString())->all())
        ->toBe(['2026-10-06 16:00:00', '2026-10-08 16:00:00', '2026-10-10 16:00:00']);
});

it('moves the article window even when its title and dates both changed', function () {
    $article = WotArticle::factory()->create();
    $window = WotEvent::factory()->for($article, 'article')->create([
        'title' => 'Token Store',
        'starts_at' => utcInstant('2026-10-01 00:00'),
        'ends_at' => utcInstant('2026-10-31 00:00'),
    ]);

    $changes = $article->syncEvents([[
        'title' => 'Token Store: Extended',
        'starts_at' => utcInstant('2026-10-03 00:00'),
        'ends_at' => utcInstant('2026-11-07 00:00'),
        'event_type' => null,
        'source' => WotEvent::SOURCE_WINDOW,
        'metadata' => null,
    ]]);

    expect($changes)->toBe(['kept' => 0, 'moved' => 1, 'renamed' => 0, 'created' => 0, 'removed' => 0])
        ->and($article->events()->sole()->is($window))->toBeTrue()
        ->and($window->fresh()->title)->toBe('Token Store: Extended')
        ->and($window->fresh()->ends_at->utc()->toDateTimeString())->toBe('2026-11-07 00:00:00');
});

it('never pairs a window with a calendar session', function () {
    $article = WotArticle::factory()->create();
    $window = WotEvent::factory()->for($article, 'article')->create([
        'title' => 'Community Stream',
        'starts_at' => utcInstant('2026-10-05 16:00'),
    ]);

    $changes = $article->syncEvents([extractedEvent()]);

    expect($changes)->toBe(['kept' => 0, 'moved' => 0, 'renamed' => 0, 'created' => 1, 'removed' => 1])
        ->and($article->events()->sole()->source)->toBe(WotEvent::SOURCE_CALENDAR);
    $this->assertModelMissing($window);
});

it('deletes events gone from the article and creates new ones', function () {
    $article = WotArticle::factory()->create();
    $dayOne = WotEvent::factory()->calendar()->for($article, 'article')->create([
        'title' => 'Day 1',
        'starts_at' => utcInstant('2026-10-05 16:00'),
    ]);
    $dayTwo = WotEvent::factory()->calendar()->for($article, 'article')->create([
        'title' => 'Day 2',
        'starts_at' => utcInstant('2026-10-06 16:00'),
    ]);

    $changes = $article->syncEvents([
        extractedEvent(['title' => 'Day 1', 'starts_at' => utcInstant('2026-10-05 16:00')]),
        extractedEvent(['title' => 'Day 3', 'starts_at' => utcInstant('2026-10-08 16:00')]),
    ]);

    expect($changes)->toBe(['kept' => 1, 'moved' => 0, 'renamed' => 0, 'created' => 1, 'removed' => 1])
        ->and($article->events()->pluck('title')->sort()->values()->all())->toBe(['Day 1', 'Day 3']);
    $this->assertModelExists($dayOne);
    $this->assertModelMissing($dayTwo);
});
