<?php

use App\Models\Finance\Holding;
use App\Services\Finance\Fleet;
use App\Services\Finance\FleetProjector;

it('values an itemised account at the sum of its positions', function () {
    $holding = Holding::factory()->create(['balance' => 999]);
    $holding->positions()->createMany([
        ['name' => 'A', 'asset_class' => 'etf', 'value' => 6000, 'expected_return' => 8, 'expense_ratio' => 0],
        ['name' => 'B', 'asset_class' => 'bond', 'value' => 4000, 'expected_return' => 3, 'expense_ratio' => 0.5],
    ]);

    $holding->load('positions');

    // (6000 x 8% + 4000 x 2.5%) / 10000
    expect($holding->value)->toBe(10000.0)
        ->and($holding->expected_rate)->toEqualWithDelta(5.8, 0.0001);
});

it('falls back to the account\'s own balance and rate when nothing is itemised', function () {
    $holding = Holding::factory()->create(['balance' => 2500, 'annual_rate' => 4]);

    expect($holding->value)->toBe(2500.0)
        ->and($holding->expected_rate)->toBe(4.0);
});

it('totals assets against liabilities', function () {
    $user = Holding::factory()->create(['balance' => 50000])->user;
    Holding::factory()->liability()->create(['user_id' => $user->id, 'balance' => 20000]);

    $fleet = new Fleet;

    expect($fleet->totals($fleet->holdings($user)))->toBe(['assets' => 50000.0, 'liabilities' => 20000.0, 'net_worth' => 30000.0]);
});

it('grows an asset at its rate and adds its contributions', function () {
    $holding = Holding::factory()->create(['balance' => 10000, 'annual_rate' => 7, 'monthly_contribution' => 0]);
    $saver = Holding::factory()->create(['balance' => 0, 'annual_rate' => 0, 'monthly_contribution' => 250]);

    $projector = new FleetProjector;

    expect($projector->project(collect([$holding->load('positions')]), 12)['assets'][12])->toEqualWithDelta(10700, 0.01)
        ->and($projector->project(collect([$saver->load('positions')]), 12)['assets'][12])->toEqualWithDelta(3000, 0.01);
});

it('pays a liability down to nothing and no further', function () {
    $loan = Holding::factory()->liability('auto_loan')->create(['balance' => 1000, 'annual_rate' => 0, 'monthly_contribution' => 300]);

    $projection = (new FleetProjector)->project(collect([$loan->load('positions')]), 6);

    expect($projection['liabilities'])->toBe([1000.0, 700.0, 400.0, 100.0, 0.0, 0.0, 0.0])
        ->and($projection['net_worth'][6])->toBe(0.0);
});

/**
 * The scenario lines are about market returns. A house and a mortgage should
 * sit still while the brokerage account moves.
 */
it('shifts the rate of investable assets only', function () {
    $user = Holding::factory()->create(['balance' => 10000, 'annual_rate' => 7])->user;
    Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'balance' => 10000, 'annual_rate' => 3]);

    $holdings = (new Fleet)->holdings($user);
    $projector = new FleetProjector;

    $expected = $projector->project($holdings, 12);
    $cautious = $projector->project($holdings, 12, -2.0);

    // 10,000 at 5% instead of 7% is 200 less; the house contributes no difference.
    expect($expected['assets'][12] - $cautious['assets'][12])->toEqualWithDelta(200, 0.01);
});

it('samples a monthly series at each year-end', function () {
    expect((new FleetProjector)->yearly(range(0, 24)))->toBe([0.0, 12.0, 24.0]);
});
