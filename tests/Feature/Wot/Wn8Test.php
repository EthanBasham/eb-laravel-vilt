<?php

use App\Models\WotExpectedValue;
use App\Services\Wargaming\Wn8Calculator;

/**
 * A vehicle whose expected values make the arithmetic easy to reason about:
 * exactly average performance in every metric should land in the "average"
 * band, and doubling damage should move it up.
 */
function expected(int $tankId = 1): WotExpectedValue
{
    return WotExpectedValue::create([
        'tank_id' => $tankId,
        'exp_damage' => 1000,
        'exp_spot' => 1.0,
        'exp_frag' => 1.0,
        'exp_def' => 1.0,
        'exp_win_rate' => 50.0,
    ]);
}

function row(int $tankId, int $battles, int $damage, int $wins, int $frags = 0, int $spots = 0, int $def = 0): array
{
    return [
        'tank_id' => $tankId, 'battles' => $battles, 'damage_dealt' => $damage,
        'wins' => $wins, 'frags' => $frags, 'spotted' => $spots, 'dropped_capture_points' => $def,
    ];
}

it('returns no rating when there is nothing to score', function () {
    expect(app(Wn8Calculator::class)->forVehicleRows([])['wn8'])->toBeNull();
});

it('scores exactly average play near the middle of the scale', function () {
    expected();

    $result = app(Wn8Calculator::class)->forVehicleRows([
        row(1, 100, 100_000, 50, 100, 100, 100),
    ]);

    // Meeting expectations in every metric is roughly 1200 WN8 by construction
    // of the formula — the point is that it is not 0 and not unicum.
    expect($result['wn8'])->toBeGreaterThan(900)
        ->and($result['wn8'])->toBeLessThan(1600)
        ->and($result['rated_battles'])->toBe(100);
});

it('rates better play higher', function () {
    expected();
    $calc = app(Wn8Calculator::class);

    $average = $calc->forVehicleRows([row(1, 100, 100_000, 50, 100, 100, 100)])['wn8'];
    $strong = $calc->forVehicleRows([row(1, 100, 200_000, 60, 150, 120, 100)])['wn8'];

    expect($strong)->toBeGreaterThan($average);
});

/**
 * XVM publishes no expected values for some newer vehicles. Those battles must
 * be excluded from the rating rather than scored against a guess, and the
 * exclusion has to be reported so the UI can be honest about coverage.
 */
it('excludes vehicles with no expected values and reports them', function () {
    expected(1);

    $result = app(Wn8Calculator::class)->forVehicleRows([
        row(1, 100, 100_000, 50, 100, 100, 100),
        row(999, 40, 80_000, 25, 50, 50, 10),
    ]);

    expect($result['rated_battles'])->toBe(100)
        ->and($result['unrated_battles'])->toBe(40);
});

it('returns no rating when every vehicle is unrated', function () {
    $result = app(Wn8Calculator::class)->forVehicleRows([row(999, 40, 80_000, 25)]);

    expect($result['wn8'])->toBeNull()
        ->and($result['unrated_battles'])->toBe(40);
});

it('never returns a negative rating for terrible play', function () {
    expected();

    $result = app(Wn8Calculator::class)->forVehicleRows([row(1, 100, 1, 0, 0, 0, 0)]);

    expect($result['wn8'])->toBeGreaterThanOrEqual(0.0);
});

it('ignores vehicles with no battles', function () {
    expected();

    expect(app(Wn8Calculator::class)->forVehicleRows([row(1, 0, 0, 0)])['wn8'])->toBeNull();
});

it('maps ratings onto the community colour bands', function (?float $wn8, string $band) {
    expect(Wn8Calculator::band($wn8))->toBe($band);
})->with([
    [null, 'unknown'],
    [100.0, 'very-bad'],
    [700.0, 'below-average'],
    [1000.0, 'average'],
    [1547.0, 'good'],
    [2500.0, 'unicum'],
    [3500.0, 'super-unicum'],
]);
