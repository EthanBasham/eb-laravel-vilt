<?php

use App\Models\WotAccount;
use App\Models\WotSnapshot;
use App\Models\WotVehicleSnapshot;
use App\Services\Wargaming\PeriodStats;

function snapshot(WotAccount $account, string $ago, int $battles, array $extra = []): WotSnapshot
{
    return WotSnapshot::create([
        'wot_account_id' => $account->id,
        'captured_at' => now()->sub($ago),
        'battles' => $battles,
        'statistics' => ['battles' => $battles, ...$extra],
    ]);
}

function period(array $result, string $label): ?array
{
    return collect($result['periods'])->firstWhere('label', $label);
}

it('reports nothing when there is no history at all', function () {
    $result = app(PeriodStats::class)->for(WotAccount::factory()->create());

    expect($result['history_since'])->toBeNull()
        ->and($result['periods'])->toBe([]);
});

/**
 * The behaviour that matters most here. With only two days of history, a
 * 30-day column must say "not enough history" rather than presenting two days
 * of play as a month's — the numbers would look plausible and be wrong.
 */
it('refuses to answer a period it lacks history for', function () {
    $account = WotAccount::factory()->create();
    snapshot($account, '2 days', 1000);
    snapshot($account, '1 hour', 1100);

    $result = app(PeriodStats::class)->for($account);

    expect(period($result, '24h')['available'])->toBeTrue()
        ->and(period($result, '30d')['available'])->toBeFalse()
        ->and(period($result, '60d')['available'])->toBeFalse();
});

it('computes a period as the difference between two captures', function () {
    $account = WotAccount::factory()->create();
    snapshot($account, '8 days', 1000, [
        'wins' => 500, 'survived_battles' => 300, 'damage_dealt' => 1_000_000,
        'damage_received' => 1_000_000, 'frags' => 1000, 'xp' => 500_000,
    ]);
    snapshot($account, '1 hour', 1100, [
        'wins' => 560, 'survived_battles' => 340, 'damage_dealt' => 1_200_000,
        'damage_received' => 1_100_000, 'frags' => 1150, 'xp' => 560_000,
    ]);

    $week = period(app(PeriodStats::class)->for($account), '7d');

    expect($week['available'])->toBeTrue()
        ->and($week['battles'])->toBe(100)
        // 60 wins in 100 battles, not the lifetime 56%.
        ->and($week['win_rate'])->toBe(60.0)
        ->and($week['survival_rate'])->toBe(40.0)
        ->and($week['avg_damage'])->toBe(2000.0)
        ->and($week['avg_frags'])->toBe(1.5)
        // 200k dealt against 100k received.
        ->and($week['damage_ratio'])->toBe(2.0)
        // 150 frags against 60 deaths.
        ->and($week['kd_ratio'])->toBe(2.5);
});

it('offers a window measured in battles rather than days', function () {
    $account = WotAccount::factory()->create();
    snapshot($account, '40 days', 5000, ['wins' => 2500]);
    snapshot($account, '20 days', 5600, ['wins' => 2800]);
    snapshot($account, '1 hour', 6200, ['wins' => 3160]);

    $window = period(app(PeriodStats::class)->for($account), '1000 battles');

    // The baseline is the most recent capture at least 1000 battles back — the
    // 5000-battle row, not the 5600 one.
    expect($window['available'])->toBeTrue()
        ->and($window['battles'])->toBe(1200);
});

it('cannot answer the battle window without enough battles recorded', function () {
    $account = WotAccount::factory()->create();
    snapshot($account, '5 days', 5000);
    snapshot($account, '1 hour', 5100);

    expect(period(app(PeriodStats::class)->for($account), '1000 battles')['available'])->toBeFalse();
});

it('treats a vehicle with no earlier snapshot as newly played', function () {
    $account = WotAccount::factory()->create();
    snapshot($account, '8 days', 1000, ['wins' => 500]);
    snapshot($account, '1 hour', 1050, ['wins' => 530]);

    // Only exists after the baseline — bought and played during the window.
    WotVehicleSnapshot::create([
        'wot_account_id' => $account->id,
        'tank_id' => 42,
        'captured_at' => now()->subHour(),
        'battles' => 50,
        'statistics' => ['battles' => 50, 'wins' => 30, 'damage_dealt' => 100_000, 'spotted' => 40, 'frags' => 60, 'dropped_capture_points' => 10],
    ]);

    $week = period(app(PeriodStats::class)->for($account), '7d');

    // All 50 battles count toward the window, not just any increment.
    expect($week['available'])->toBeTrue()
        ->and($week['battles'])->toBe(50);
});

it('records the point history began', function () {
    $account = WotAccount::factory()->create();
    snapshot($account, '3 days', 100);
    snapshot($account, '1 hour', 200);

    expect(app(PeriodStats::class)->for($account)['history_since'])->not->toBeNull();
});
