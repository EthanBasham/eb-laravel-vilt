<?php

use Illuminate\Support\Facades\Http;
use App\Models\WotArticle;
use App\Models\WotEvent;
use App\Services\WotNews\EventExtractor;
use App\Services\WotNews\FeedParser;
use App\Services\WotNews\NewsClient;
use App\Services\WotNews\NewsFetchException;

function newsFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/{$name}"));
}

// --- Feed parsing -----------------------------------------------------------

it('parses the RSS feed into articles', function () {
    $items = app(FeedParser::class)->parse(newsFixture('news.rss'));

    expect($items)->toHaveCount(2)
        ->and($items[0]['title'])->toBe('AMD OLS Token Store: Watch Season 7 for Great Rewards')
        ->and($items[0]['category'])->toBe('Live Streams')
        ->and($items[0]['image_url'])->toBe('https://example.test/image.jpg')
        ->and($items[0]['published_at']->toDateString())->toBe('2026-09-07')
        // Feed descriptions are HTML fragments; stored as text so third-party
        // markup never reaches the page.
        ->and($items[0]['description'])->toBe('Tune in to all of the action on Twitch and earn Tokens.');
});

it('does not fail on malformed feed XML', function () {
    expect(fn () => app(FeedParser::class)->parse('not xml at all'))
        ->toThrow(NewsFetchException::class);
});

// --- Event extraction -------------------------------------------------------

it('extracts exact session times from an event calendar', function () {
    $events = app(EventExtractor::class)->extract(newsFixture('calendar-article.html'), 'Fallback');

    expect($events)->toHaveCount(2)
        ->and($events[0]['title'])->toBe('AMD OLS#7 Phase 1 Day 1')
        ->and($events[0]['source'])->toBe(WotEvent::SOURCE_CALENDAR)
        ->and($events[0]['event_type'])->toBe('stream')
        ->and($events[0]['starts_at']->toDateTimeString())->toBe('2026-09-08 16:00:00')
        ->and($events[0]['ends_at']->toDateTimeString())->toBe('2026-09-08 22:59:00')
        ->and($events[0]['metadata']['tokens'])->toBe('5')
        ->and($events[0]['metadata']['rewards'])->toContain('5 Tokens');
});

/**
 * The live site emits attributes with no separating whitespace
 * (data-accent="stream"data-date="…"). The fixture reproduces that exactly,
 * because a parser that silently stopped handling it would produce an empty
 * calendar rather than an error.
 */
it('handles the run-together attributes the live site emits', function () {
    expect(newsFixture('calendar-article.html'))->toContain('"stream"data-date=');

    $events = app(EventExtractor::class)->extract(newsFixture('calendar-article.html'), 'Fallback');

    expect($events[1]['starts_at']->toDateTimeString())->toBe('2026-09-10 16:00:00');
});

it('falls back to a coarse window when there is no calendar', function () {
    $events = app(EventExtractor::class)->extract(newsFixture('window-article.html'), 'Boosteroid September');

    expect($events)->toHaveCount(1)
        ->and($events[0]['source'])->toBe(WotEvent::SOURCE_WINDOW)
        ->and($events[0]['title'])->toBe('Boosteroid September')
        ->and($events[0]['starts_at']->toDateTimeString())->toBe('2026-09-07 09:00:00')
        ->and($events[0]['ends_at']->toDateTimeString())->toBe('2026-10-19 09:00:00');
});

/**
 * The calendar article also carries a data-timestamp pair for the overall
 * campaign. Emitting both would draw a duplicate month-long bar behind every
 * session, so the precise tier wins outright.
 */
it('prefers calendar sessions over the article window', function () {
    $events = app(EventExtractor::class)->extract(newsFixture('calendar-article.html'), 'Fallback');

    expect(collect($events)->pluck('source')->unique()->all())->toBe([WotEvent::SOURCE_CALENDAR]);
});

it('extracts nothing from an article that only describes dates in prose', function () {
    expect(app(EventExtractor::class)->extract(newsFixture('plain-article.html'), 'Update 2.4'))->toBe([]);
});

// --- robots.txt -------------------------------------------------------------

it('refuses paths robots.txt disallows', function (string $url, bool $allowed) {
    expect(app(NewsClient::class)->mayFetch($url))->toBe($allowed);
})->with([
    ['https://worldoftanks.com/en/news/specials/anything/', true],
    ['https://worldoftanks.com/en/news/wot-assistant/thing/', false],
    ['https://worldoftanks.com/en/news/wgc-client/thing/', false],
]);

// --- The command -------------------------------------------------------------

it('stores articles and their events', function () {
    config(['wotnews.categories' => ['live-streams']]);

    Http::fake([
        '*/rss/news/*' => Http::response(newsFixture('news.rss')),
        '*token-store-september-2026/' => Http::response(newsFixture('calendar-article.html')),
        '*micro-patch-040926/' => Http::response(newsFixture('plain-article.html')),
    ]);

    $this->artisan('wot:sync-news')->assertSuccessful();

    expect(WotArticle::count())->toBe(2)
        ->and(WotEvent::count())->toBe(2)
        ->and(WotArticle::where('title', 'like', 'AMD OLS%')->first()->events)->toHaveCount(2);
});

it('is idempotent — re-syncing updates rather than duplicating', function () {
    config(['wotnews.categories' => ['live-streams']]);
    Http::fake([
        '*/rss/news/*' => Http::response(newsFixture('news.rss')),
        '*' => Http::response(newsFixture('calendar-article.html')),
    ]);

    $this->artisan('wot:sync-news');
    $this->artisan('wot:sync-news');

    expect(WotArticle::count())->toBe(2)
        // Two articles served the same calendar fixture; each keeps its own two
        // events rather than accumulating four apiece.
        ->and(WotEvent::count())->toBe(4);
});

it('does not re-parse an article whose body has not changed', function () {
    config(['wotnews.categories' => ['live-streams']]);
    Http::fake([
        '*/rss/news/*' => Http::response(newsFixture('news.rss')),
        '*' => Http::response(newsFixture('calendar-article.html')),
    ]);

    $this->artisan('wot:sync-news');
    $first = WotArticle::where('title', 'like', 'AMD OLS%')->first();
    $hash = $first->body_hash;

    $this->artisan('wot:sync-news');

    expect($first->fresh()->body_hash)->toBe($hash)
        ->and($first->fresh()->needsBodyFetch())->toBeFalse();
});

it('marks a disallowed article fetched so it is not retried forever', function () {
    config(['wotnews.categories' => ['live-streams']]);
    WotArticle::factory()->create(['url' => 'https://worldoftanks.com/en/news/wot-assistant/thing/']);
    Http::fake(['*/rss/news/*' => Http::response('<rss version="2.0"><channel></channel></rss>')]);

    $this->artisan('wot:sync-news');

    $article = WotArticle::where('url', 'like', '%wot-assistant%')->first();

    expect($article->body_fetched_at)->not->toBeNull()
        ->and($article->needsBodyFetch())->toBeFalse();
});
