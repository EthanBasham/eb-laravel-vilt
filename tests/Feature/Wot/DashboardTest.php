<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotArticle;
use App\Models\WotEvent;
use App\Models\WotVehicle;

/** Wargaming's account/info shape, trimmed to what the dashboard reads. */
function accountInfoResponse(int $accountId): array
{
    return ['status' => 'ok', 'data' => [(string) $accountId => [
        'nickname' => 'EthanB',
        'global_rating' => 6210,
        'last_battle_time' => 1757280000,
        'created_at' => 1300000000,
        'statistics' => ['all' => [
            'battles' => 1000, 'wins' => 520, 'losses' => 460, 'survived_battles' => 300,
            'damage_dealt' => 1_200_000, 'xp' => 600_000, 'frags' => 900, 'max_damage' => 5400,
        ]],
    ]]];
}

function tankStatsResponse(int $accountId, int $tankId): array
{
    return ['status' => 'ok', 'data' => [(string) $accountId => [
        ['tank_id' => $tankId, 'mark_of_mastery' => 4, 'all' => [
            'battles' => 120, 'wins' => 72, 'damage_dealt' => 240_000, 'xp' => 96_000,
        ]],
        // A tank the local encyclopedia doesn't know about — added by a patch
        // since the last sync. Should be dropped, not rendered nameless.
        ['tank_id' => 999999, 'mark_of_mastery' => 0, 'all' => ['battles' => 5, 'wins' => 1]],
    ]]];
}

/** Wargaming's tanks/achievements shape — where Marks of Excellence live. */
function achievementsResponse(int $accountId, int $tankId): array
{
    return ['status' => 'ok', 'data' => [(string) $accountId => [
        ['tank_id' => $tankId, 'achievements' => ['marksOnGun' => 3, 'markOfMastery' => 4]],
    ]]];
}

it('shows the connect screen when no account is linked', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('wot.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Connect'));
});

it('renders the summary and garage for a linked account', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 1005000001]);
    $vehicle = WotVehicle::factory()->create(['name' => 'T-54', 'tier' => 9, 'nation' => 'ussr']);

    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(1005000001)),
        '*/tanks/stats/*' => Http::response(tankStatsResponse(1005000001, $vehicle->tank_id)),
        '*/tanks/achievements/*' => Http::response(achievementsResponse(1005000001, $vehicle->tank_id)),
    ]);

    $this->actingAs($user)
        ->get(route('wot.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('account.nickname', $account->nickname)
            ->where('summary.battles', 1000)
            ->where('summary.win_rate', 52)
            ->where('summary.avg_damage', 1200)
            ->has('vehicles', 1)
            ->where('vehicles.0.name', 'T-54')
            ->where('vehicles.0.win_rate', 60)
            ->where('vehicles.0.avg_damage', 2000)
            ->where('vehicles.0.mastery', 4)
            ->where('vehicles.0.marks', 3)
            ->has('achievements.marks_of_excellence')
            ->where('achievements.marks_of_excellence.three', 1)
            ->where('achievements.mastery.ace', 1)
            ->has('history.periods'),
        );
});

it('records when the account was last synced', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7, 'last_synced_at' => null]);
    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'));

    expect($account->fresh()->last_synced_at)->not->toBeNull();
});

it('omits the private block when the API returns no private data', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)
        ->get(route('wot.dashboard'))
        ->assertInertia(fn ($page) => $page->where('summary.private', null));
});

it('renders an error instead of failing when the API rejects the call', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'INVALID_IP_ADDRESS', 'value' => '203.0.113.7'],
    ])]);

    $this->actingAs($user)
        ->get(route('wot.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->whereNot('error', null));
});

/**
 * A rejected token is recoverable by reconnecting, so the link survives and
 * only the credential is dropped.
 */
it('clears a rejected token but keeps the account linked', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'INVALID_ACCESS_TOKEN'],
    ])]);

    $this->actingAs($user)->get(route('wot.dashboard'))->assertOk();

    $account->refresh();

    expect($account->exists)->toBeTrue()
        ->and($account->access_token)->toBeNull()
        ->and($account->is_token_valid)->toBeFalse();
});

it('caches API responses rather than re-fetching per page view', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'));
    $this->actingAs($user)->get(route('wot.dashboard'));

    // Three endpoints, hit once each across two page views.
    Http::assertSentCount(3);
});

/**
 * Payloads are cached for thirty minutes, so a player who has just finished a
 * session needs a way to see live numbers without waiting it out.
 */
it('refresh drops the cached payloads', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'));
    expect(Cache::has("wot:payloads:{$account->account_id}"))->toBeTrue();

    $this->actingAs($user)->post(route('wot.dashboard.refresh'))->assertRedirect();

    expect(Cache::has("wot:payloads:{$account->account_id}"))->toBeFalse();
});

it('refresh requires a linked account', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('wot.dashboard.refresh'))
        ->assertNotFound();
});

/**
 * The three payloads are fetched concurrently and stored as a single entry, so
 * a second page view should issue no requests at all.
 */
it('fetches all three payloads once and reuses them', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'));
    $this->actingAs($user)->get(route('wot.dashboard'));

    Http::assertSentCount(3);
});

// --- The two panels above the statistics -------------------------------------

it('shows the five newest articles and the pinned ones separately', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    $articles = collect(range(1, 7))->map(fn (int $i) => WotArticle::factory()->create([
        'title' => "Article {$i}",
        'published_at' => now()->subDays(7 - $i),
    ]));
    $this->actingAs($user)->post(route('wot.news.pin', $articles->first()));

    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'))->assertInertia(fn ($page) => $page
        ->has('news.latest', 5)
        // Article 1 was pinned, and Latest hoists pinned articles so a pin is
        // visible without switching tabs; Article 7 is the newest unpinned one.
        ->where('news.latest.0.title', 'Article 1')
        ->where('news.latest.0.is_pinned', true)
        ->where('news.latest.1.title', 'Article 7')
        ->has('news.pinned', 1)
        ->where('news.pinned.0.title', 'Article 1'),
    );
});

it('marks unseen articles in the panel', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    $article = WotArticle::factory()->create();
    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'))
        ->assertInertia(fn ($page) => $page->where('news.latest.0.is_seen', false));

    $this->actingAs($user)->post(route('wot.news.seen'), ['ids' => [$article->id]]);

    $this->actingAs($user)->get(route('wot.dashboard'))
        ->assertInertia(fn ($page) => $page->where('news.latest.0.is_seen', true));
});

it('buckets upcoming events into five days', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    $article = WotArticle::factory()->create();

    // A timed session tomorrow, and a campaign spanning months.
    WotEvent::create([
        'wot_article_id' => $article->id, 'title' => 'Stream tomorrow',
        'starts_at' => now()->addDay()->setTime(16, 0), 'ends_at' => now()->addDay()->setTime(22, 59),
        'event_type' => 'stream', 'source' => WotEvent::SOURCE_CALENDAR,
    ]);
    WotEvent::create([
        'wot_article_id' => $article->id, 'title' => 'Battle Pass',
        'starts_at' => now()->subDays(3), 'ends_at' => now()->addDays(60),
        'source' => WotEvent::SOURCE_WINDOW,
    ]);

    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'))->assertInertia(fn ($page) => $page
        ->has('upcoming.days', 5)
        ->where('upcoming.days.0.is_today', true)
        // The session lands on its own day, with a time.
        ->has('upcoming.days.1.events', 1)
        ->where('upcoming.days.1.events.0.time', '16:00')
        // The 60-day campaign is summarised once, not repeated across all five.
        ->has('upcoming.ongoing', 1)
        ->where('upcoming.ongoing.0.title', 'Battle Pass')
        ->has('upcoming.days.0.events', 0),
    );
});

/**
 * The panels read local tables, so a Wargaming outage should cost the numbers
 * below them, not the whole page.
 */
it('still renders the panels when the API fails', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();
    WotArticle::factory()->create(['title' => 'Still here']);
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'SOURCE_NOT_AVAILABLE'],
    ])]);

    $this->actingAs($user)->get(route('wot.dashboard'))->assertInertia(fn ($page) => $page
        ->whereNot('error', null)
        ->where('summary', null)
        ->where('news.latest.0.title', 'Still here')
        ->has('upcoming.days', 5),
    );
});

it('reports pinned state in the dashboard news panel', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    $older = WotArticle::factory()->create(['title' => 'Older', 'published_at' => now()->subWeek()]);
    WotArticle::factory()->create(['title' => 'Newer', 'published_at' => now()]);

    Http::fake([
        '*/account/info/*' => Http::response(accountInfoResponse(7)),
        '*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
        '*/tanks/achievements/*' => Http::response(['status' => 'ok', 'data' => ['7' => []]]),
    ]);

    $this->actingAs($user)->get(route('wot.dashboard'))->assertInertia(fn ($page) => $page
        ->where('news.latest.0.title', 'Newer')
        ->where('news.latest.0.is_pinned', false),
    );

    $this->actingAs($user)->post(route('wot.news.pin', $older));

    // Pinning hoists it on the Latest tab too, so the pin is visible without
    // switching tabs.
    $this->actingAs($user)->get(route('wot.dashboard'))->assertInertia(fn ($page) => $page
        ->where('news.latest.0.title', 'Older')
        ->where('news.latest.0.is_pinned', true)
        ->where('news.latest.1.is_pinned', false)
        ->has('news.pinned', 1),
    );
});
