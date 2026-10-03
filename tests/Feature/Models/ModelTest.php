<?php

use Illuminate\Database\Eloquent\Builder;
use App\Models\User;
use App\Models\WotArticle;
use App\Models\WotEvent;

beforeEach(fn () => test()->travelTo('2026-09-15 12:00:00'));

/**
 * The titles a query returns, in start order.
 *
 * @return list<string>
 */
function eventTitles(Builder $query): array
{
    return $query->orderBy('starts_at')->pluck('title')->all();
}

it('keeps rows at or after now by default, to the second', function () {
    WotEvent::factory()->count(3)->sequence(
        ['title' => 'Just past', 'starts_at' => '2026-09-15 11:59:59'],
        ['title' => 'Now', 'starts_at' => '2026-09-15 12:00:00'],
        ['title' => 'Just ahead', 'starts_at' => '2026-09-15 12:00:01'],
    )->create();

    expect(eventTitles(WotEvent::onlyOnOrAfter('starts_at')))->toBe(['Now', 'Just ahead']);
});

it('keeps rows at or before a given date, to the second', function () {
    WotEvent::factory()->count(3)->sequence(
        ['title' => 'Before', 'starts_at' => '2026-09-10 08:59:59'],
        ['title' => 'At', 'starts_at' => '2026-09-10 09:00:00'],
        ['title' => 'After', 'starts_at' => '2026-09-10 09:00:01'],
    )->create();

    expect(eventTitles(WotEvent::onlyOnOrBefore('starts_at', '2026-09-10 09:00:00')))->toBe(['Before', 'At']);
});

it('leaves out rows with no value unless asked for them', function () {
    WotEvent::factory()->count(3)->sequence(
        ['title' => 'Ended', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-02 00:00:00'],
        ['title' => 'Open-ended', 'starts_at' => '2026-09-03 00:00:00', 'ends_at' => null],
        ['title' => 'Running', 'starts_at' => '2026-09-04 00:00:00', 'ends_at' => '2026-09-30 00:00:00'],
    )->create();

    expect(eventTitles(WotEvent::onlyOnOrAfter('ends_at')))->toBe(['Running'])
        ->and(eventTitles(WotEvent::onlyOnOrAfter('ends_at', orNull: true)))->toBe(['Open-ended', 'Running']);
});

it('rounds a day range out to whole days', function () {
    WotEvent::factory()->count(4)->sequence(
        ['title' => 'Day before', 'starts_at' => '2026-09-09 23:59:59'],
        ['title' => 'First day', 'starts_at' => '2026-09-10 00:00:00'],
        ['title' => 'Last day', 'starts_at' => '2026-09-12 23:59:59'],
        ['title' => 'Day after', 'starts_at' => '2026-09-13 00:00:00'],
    )->create();

    expect(eventTitles(WotEvent::onlyWithinDays('starts_at', '2026-09-10 15:00', '2026-09-12 01:00')))
        ->toBe(['First day', 'Last day']);
});

it('leaves a day range open at a null end', function () {
    WotEvent::factory()->count(2)->sequence(
        ['title' => 'Day before', 'starts_at' => '2026-09-09 23:59:59'],
        ['title' => 'Much later', 'starts_at' => '2027-01-01 00:00:00'],
    )->create();

    expect(eventTitles(WotEvent::onlyWithinDays('starts_at', from: '2026-09-10')))->toBe(['Much later']);
});

it('adds rows with no value to a closed day range when asked', function () {
    WotEvent::factory()->count(3)->sequence(
        ['title' => 'Inside', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-11 00:00:00'],
        ['title' => 'Open-ended', 'starts_at' => '2026-09-02 00:00:00', 'ends_at' => null],
        ['title' => 'Outside', 'starts_at' => '2026-09-03 00:00:00', 'ends_at' => '2026-09-20 00:00:00'],
    )->create();

    expect(eventTitles(WotEvent::onlyWithinDays('ends_at', '2026-09-10', '2026-09-12', orNull: true)))
        ->toBe(['Inside', 'Open-ended']);
});

it('returns every row for a day range with no bounds', function () {
    WotEvent::factory()->count(2)->create();

    expect(WotEvent::onlyWithinDays('starts_at')->count())->toBe(2);
});

it('throws on a bound it cannot read instead of dropping it', function (Closure $query) {
    expect($query)->toThrow(InvalidArgumentException::class);
})->with([
    'on or after' => [fn () => WotEvent::onlyOnOrAfter('starts_at', 'garbage')],
    'on or before' => [fn () => WotEvent::onlyOnOrBefore('starts_at', '')],
    'within days, from' => [fn () => WotEvent::onlyWithinDays('starts_at', '2026-13-01', '2026-09-30')],
    'within days, to' => [fn () => WotEvent::onlyWithinDays('starts_at', '2026-09-01', 'garbage')],
    'overlapping days' => [fn () => WotEvent::onlyOverlappingDays('starts_at', 'ends_at', 'garbage', '2026-09-30')],
]);

it('qualifies the column so it stays unambiguous under a join', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();
    $article->pinBy($user);

    expect(WotArticle::withPinnedFor($user)->onlyOnOrBefore('created_at')->count())->toBe(1);
});

it('keeps spans that overlap a day range in any way', function () {
    WotEvent::factory()->count(6)->sequence(
        ['title' => 'Over before', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-09 23:59:59'],
        ['title' => 'Straddles start', 'starts_at' => '2026-09-02 00:00:00', 'ends_at' => '2026-09-10 00:00:00'],
        ['title' => 'Inside', 'starts_at' => '2026-09-11 00:00:00', 'ends_at' => '2026-09-11 06:00:00'],
        ['title' => 'Straddles end', 'starts_at' => '2026-09-12 23:59:59', 'ends_at' => '2026-09-14 00:00:00'],
        ['title' => 'Covers it', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-30 00:00:00'],
        ['title' => 'Starts after', 'starts_at' => '2026-09-13 00:00:00', 'ends_at' => '2026-09-14 00:00:00'],
    )->create();

    expect(eventTitles(WotEvent::onlyOverlappingDays('starts_at', 'ends_at', '2026-09-10 15:00', '2026-09-12 01:00')))
        ->toBe(['Covers it', 'Straddles start', 'Inside', 'Straddles end']);
});

it('treats a span with no end as a single moment by default', function () {
    WotEvent::factory()->count(2)->sequence(
        ['title' => 'Moment before', 'starts_at' => '2026-09-09 12:00:00', 'ends_at' => null],
        ['title' => 'Moment inside', 'starts_at' => '2026-09-11 12:00:00', 'ends_at' => null],
    )->create();

    expect(eventTitles(WotEvent::onlyOverlappingDays('starts_at', 'ends_at', '2026-09-10', '2026-09-12')))
        ->toBe(['Moment inside']);
});

it('treats a span with no end as still running when asked', function () {
    WotEvent::factory()->count(2)->sequence(
        ['title' => 'Running since before', 'starts_at' => '2026-09-01 12:00:00', 'ends_at' => null],
        ['title' => 'Starts after', 'starts_at' => '2026-09-13 00:00:00', 'ends_at' => null],
    )->create();

    expect(eventTitles(WotEvent::onlyOverlappingDays('starts_at', 'ends_at', '2026-09-10', '2026-09-12', openEnded: true)))
        ->toBe(['Running since before']);
});

it('leaves an overlap range open at a null end', function () {
    WotEvent::factory()->count(2)->sequence(
        ['title' => 'Long ago', 'starts_at' => '2020-01-01 00:00:00', 'ends_at' => '2020-01-02 00:00:00'],
        ['title' => 'Starts after', 'starts_at' => '2026-09-13 00:00:00', 'ends_at' => '2026-09-14 00:00:00'],
    )->create();

    expect(eventTitles(WotEvent::onlyOverlappingDays('starts_at', 'ends_at', to: '2026-09-12')))->toBe(['Long ago']);
});

it('qualifies both span columns so they stay unambiguous under a join', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();
    $article->pinBy($user);

    expect(WotArticle::withPinnedFor($user)->onlyOverlappingDays('created_at', 'updated_at', now(), now())->count())->toBe(1);
});
