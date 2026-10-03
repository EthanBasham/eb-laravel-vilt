<?php

use App\Models\Finance\Flow;
use App\Models\Finance\Goal;
use App\Models\Finance\Holding;
use App\Models\User;
use App\Services\Finance\Calculators;
use App\Services\Finance\GoalPlanner;
use App\Services\Finance\RealEstateAnalyzer;
use App\Services\Finance\ToolInput;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

// Tool input

it('clamps a tool input into its range and defaults what it cannot read', function () {
    $numbers = ToolInput::numbers(['high' => 500, 'low' => -5, 'text' => 'abc'], [
        'high' => [10, 0, 100],
        'low' => [10, 0, 100],
        'text' => [10, 0, 100],
        'missing' => [10, 0, 100],
    ]);

    expect($numbers)->toBe(['high' => 100.0, 'low' => 0.0, 'text' => 10.0, 'missing' => 10.0]);
});

// Real estate

/**
 * Bought outright, so every return measure is the same number and can be
 * read straight off the rent: $24,000 less $3,600 of costs on $200,000.
 */
function cashPurchase(array $overrides = []): array
{
    return app(RealEstateAnalyzer::class)->analyze([
        'name' => 'Test house', 'price' => 200000, 'down_pct' => 100, 'closing_pct' => 0,
        'rent' => 2000, 'vacancy_pct' => 0, 'tax_annual' => 2400, 'insurance_annual' => 1200,
        'maintenance_pct' => 0, 'management_pct' => 0, 'hoa_monthly' => 0,
        'appreciation_pct' => 0, 'rent_growth_pct' => 0,
        ...$overrides,
    ], ['hold_years' => 1.0, 'selling_pct' => 0.0]);
}

it('screens a property bought for cash', function () {
    $analysis = cashPurchase();

    expect($analysis['noi'])->toEqual(20400)
        ->and($analysis['cap_rate'])->toBe(10.2)
        ->and($analysis['cash_on_cash'])->toBe(10.2)
        ->and($analysis['irr'])->toBe(10.2)
        ->and($analysis['monthly_cashflow'])->toEqual(1700)
        ->and($analysis['dscr'])->toBeNull();
});

it('takes vacancy and management out of the rent collected', function () {
    // 10% vacant leaves $21,600; 10% of that to the manager leaves $19,440.
    expect(cashPurchase(['vacancy_pct' => 10, 'management_pct' => 10])['noi'])->toEqual(19440 - 3600);
});

it('services the debt out of operating income on a financed purchase', function () {
    $analysis = cashPurchase(['down_pct' => 25, 'rate' => 6, 'term_years' => 30]);

    // $150,000 at 6% over 30 years is $899.33 a month.
    expect($analysis['payment'])->toBe(899.33)
        ->and($analysis['cash_in'])->toEqual(50000)
        ->and($analysis['dscr'])->toBe(1.89)
        ->and($analysis['cap_rate'])->toBe(10.2);
});

it('solves an internal rate of return', function () {
    $analyzer = app(RealEstateAnalyzer::class);

    expect($analyzer->irr([-100.0, 110.0]))->toBe(10.0)
        ->and($analyzer->irr([-100.0, -5.0]))->toBeNull();
});

it('opens the comparator on the rented-out real estate already in the fleet', function () {
    $user = User::factory()->create();
    $duplex = Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'name' => 'Maple St duplex', 'balance' => 400000]);
    Holding::factory()->liability('mortgage')->create(['user_id' => $user->id, 'balance' => 300000, 'annual_rate' => 6.1, 'secured_by_id' => $duplex->id]);
    Flow::factory()->income('rental')->create(['user_id' => $user->id, 'holding_id' => $duplex->id, 'amount' => 3000, 'frequency' => 'monthly']);

    // Their own home: no rent, so nothing to screen.
    Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'name' => 'Family home', 'balance' => 900000]);

    $properties = app(RealEstateAnalyzer::class)->for($user, [])['properties'];
    $first = $properties[0];

    expect(collect($properties)->pluck('name'))->not->toContain('Family home');

    expect($first['name'])->toBe('Maple St duplex')
        ->and($first['inputs']['price'])->toBe(400000.0)
        ->and($first['inputs']['down_pct'])->toBe(25.0)
        ->and($first['inputs']['rate'])->toBe(6.1)
        ->and($first['inputs']['rent'])->toBe(3000.0);
});

it('compares at most three properties', function () {
    $properties = array_fill(0, 5, ['price' => 100000]);

    expect(app(RealEstateAnalyzer::class)->for(User::factory()->create(), ['properties' => $properties])['properties'])->toHaveCount(3);
});

// Goals

it('prices each way of reaching a goal', function () {
    $goal = Goal::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Car', 'target_amount' => 12000, 'saved_amount' => 0, 'target_date' => '2027-10-01',
    ]);

    $plan = app(GoalPlanner::class)->plan($goal, ['savings_rate' => 4.0, 'market_return' => 7.0, 'loan_rate' => 8.0, 'loan_years' => 5.0]);
    $by = collect($plan['strategies'])->keyBy('key');

    expect($plan['months_remaining'])->toBe(12)
        ->and($by['cash']['monthly'])->toBe(1000.0)
        ->and($by['hysa']['monthly'])->toBeLessThan(1000.0)
        ->and($by['invest']['monthly'])->toBeLessThan($by['hysa']['monthly'])
        // Investing is cheaper only if returns show up; the plan says what
        // is missing if they do not.
        ->and($by['invest']['downside_shortfall'])->toBeGreaterThan(0)
        // $12,000 at 8% over five years.
        ->and($by['finance']['monthly'])->toBe(243.32)
        ->and($by['finance']['growth'])->toBeLessThan(0);
});

it('still asks for one deposit on a goal that is already due', function () {
    $goal = Goal::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Overdue', 'target_amount' => 5000, 'saved_amount' => 1000, 'target_date' => '2026-01-01',
    ]);

    expect($goal->months_remaining)->toBe(1);
});

// Calculators

it('works out a mortgage, with PMI only below 20% down', function () {
    $calculators = new Calculators;

    $standard = $calculators->mortgage(['price' => 375000, 'down_pct' => 20, 'rate' => 6, 'term_years' => 30, 'tax_annual' => 0, 'insurance_annual' => 0]);
    $thin = $calculators->mortgage(['price' => 375000, 'down_pct' => 10, 'rate' => 6, 'term_years' => 30]);

    expect($standard['loan'])->toEqual(300000)
        ->and($standard['payment'])->toBe(1798.65)
        ->and($standard['monthly_total'])->toBe(1798.65)
        ->and($standard['pmi'])->toBe(0.0)
        ->and($thin['pmi'])->toBe(140.63);
});

it('shows what an extra mortgage payment saves', function () {
    $result = (new Calculators)->mortgage(['price' => 375000, 'down_pct' => 20, 'rate' => 6, 'extra_monthly' => 300]);

    expect($result['accelerated']['months_saved'])->toBeGreaterThan(0)
        ->and($result['accelerated']['interest_saved'])->toBeGreaterThan(0);
});

it('compounds a balance, and deflates it when asked', function () {
    $calculators = new Calculators;

    expect($calculators->compound(['principal' => 10000, 'monthly' => 0, 'rate' => 7, 'years' => 1])['balance'])->toEqual(10700)
        ->and($calculators->compound(['principal' => 10000, 'monthly' => 0, 'rate' => 7, 'years' => 1, 'inflation' => 7])['balance'])->toEqual(10000);
});

it('says so when a payment will never clear a debt', function () {
    $result = (new Calculators)->payoff(['balance' => 10000, 'rate' => 24, 'payment' => 100, 'extra_monthly' => 0]);

    expect($result['standard']['paid_off'])->toBeFalse()
        ->and($result['months_saved'])->toBeNull();
});

it('finds the monthly saving that reaches a target', function () {
    $result = (new Calculators)->savings(['target' => 12000, 'present' => 0, 'rate' => 0, 'years' => 1]);

    expect($result['monthly'])->toBe(1000.0)
        ->and($result['years'][1]['balance'])->toEqual(12000);
});
