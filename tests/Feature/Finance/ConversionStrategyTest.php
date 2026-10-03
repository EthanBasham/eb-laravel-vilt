<?php

use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\User;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\TaxCalculator;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * A single retiree of 68 who plans to 80, with $1,000,000 traditional and
 * enough in a brokerage account to pay any tax from. No inflation and no
 * growth, so a figure in the plan is a figure that can be worked out by hand.
 */
function conversionRetiree(float $brokerage = 2_000_000): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1958-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => $brokerage, 'annual_rate' => 0]);

    return $user;
}

/**
 * The strategy as the page receives it: its settings, rows and summary.
 *
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function runStrategy(User $user, string $kind, array $settings = []): array
{
    $strategy = ConversionStrategy::factory()->ofKind($kind, ['growth_rate' => 0, ...$settings])->create(['user_id' => $user->id]);

    return collect(app(ConversionBoard::class)->for($user)['strategies'])->firstWhere('id', $strategy->id);
}

/** The row for an age: the plan opens at 68. */
function atAge(array $strategy, int $age): array
{
    return collect($strategy['rows'])->firstWhere('age', $age);
}

// The four kinds of strategy

it('converts nothing and takes the RMDs as they come', function () {
    $plan = runStrategy(conversionRetiree(), 'none');

    // Born before 1960: RMDs from 73, on the IRS divisor of 26.5.
    expect($plan['summary']['converted'])->toEqual(0)
        ->and(atAge($plan, 72)['rmd'])->toEqual(0)
        ->and(atAge($plan, 73)['rmd'])->toEqual(round(1_000_000 / 26.5))
        ->and($plan['rows'])->toHaveCount(13);
});

it('converts the whole balance in the one year', function () {
    $plan = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 70]);

    expect(atAge($plan, 69)['conversion'])->toEqual(0)
        ->and(atAge($plan, 70)['conversion'])->toEqual(1_000_000)
        ->and(atAge($plan, 70)['traditional'])->toEqual(0)
        ->and(atAge($plan, 70)['roth'])->toEqual(1_000_000)
        ->and($plan['summary']['converted'])->toEqual(1_000_000)
        // Nothing left to distribute.
        ->and($plan['summary']['total_rmd'])->toEqual(0);
});

it('converts at once when the year named has already passed', function () {
    $plan = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 60]);

    expect(atAge($plan, 68)['conversion'])->toEqual(1_000_000)
        ->and($plan['assumptions']['convert_from_age'])->toBe(68);
});

it('empties the balance in equal parts across the window', function () {
    // The usual window is 65 through 72; at 68 that leaves five years.
    $plan = runStrategy(conversionRetiree(), 'even');

    expect($plan['assumptions'])->toMatchArray(['convert_from_age' => 68, 'convert_until_age' => 72])
        ->and(collect($plan['rows'])->whereBetween('age', [68, 72])->pluck('conversion')->all())->toEqual([200_000, 200_000, 200_000, 200_000, 200_000])
        ->and(atAge($plan, 73)['conversion'])->toEqual(0)
        ->and($plan['summary']['ending_traditional'])->toEqual(0);
});

it('fills the named bracket each year, leaving room for the RMD', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12]);

    // $66,500 is where the 12% bracket tops out for a single filer with no
    // other income, and that is what the year's income comes to.
    expect(atAge($plan, 68)['conversion'])->toEqual(66_500)
        ->and(atAge($plan, 68)['bracket_income'])->toEqual(66_500)
        ->and(atAge($plan, 68)['bracket_income_before'])->toEqual(0)
        ->and(atAge($plan, 68)['marginal_rate'])->toEqual(22)
        ->and(atAge($plan, 73)['rmd'] + atAge($plan, 73)['conversion'])->toEqual(66_500);
});

it('fills whichever bracket the income is already in when none is named', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 30_000, 'frequency' => 'annual']);

    // $30,000 is in the 12% bracket, which has $36,500 of room left.
    expect(atAge(runStrategy($user, 'fill_bracket'), 68)['conversion'])->toEqual(36_500);
});

/**
 * A $100,000 pension, single: the 22% bracket has $21,800 of room, but the
 * first IRMAA line, $109,000, is only $9,000 away.
 */
it('stops at the top of the IRMAA tier when that comes before the top of the bracket', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);

    $bracketOnly = runStrategy($user, 'fill_bracket');
    $both = runStrategy($user, 'fill_bracket_irmaa');

    expect(atAge($bracketOnly, 68)['conversion'])->toEqual(21_800)
        // The premium at 70 is set by the income at 68.
        ->and(atAge($bracketOnly, 70)['irmaa'])->toBeGreaterThan(0)
        ->and(atAge($both, 68)['conversion'])->toEqual(9_000)
        ->and(atAge($both, 68)['magi'])->toEqual(109_000)
        ->and(atAge($both, 68)['magi_tier'])->toBe(0)
        ->and(atAge($both, 70)['irmaa'])->toEqual(0)
        // It converts less for it, and so leaves more to be distributed.
        ->and($both['summary']['converted'])->toBeLessThan($bracketOnly['summary']['converted']);
});

it('stops at the top of the bracket when that comes before the IRMAA line', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    // $60,000 is in the 12% bracket, which tops out at $66,500, long before $109,000.
    expect(atAge(runStrategy($user, 'fill_bracket_irmaa'), 68)['conversion'])->toEqual(6_500)
        // Naming a higher bracket leaves the IRMAA line as the one that binds.
        ->and(atAge(runStrategy($user, 'fill_bracket_irmaa', ['fill_rate' => 24]), 68)['conversion'])->toEqual(49_000);
});

it('ignores the IRMAA tiers before 63, when no premium can be set by the income', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1970-03-01', 'retirement_age' => 50, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 5_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 2_000_000, 'annual_rate' => 0]);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);

    $plan = runStrategy($user, 'fill_bracket_irmaa');

    expect(atAge($plan, 62)['conversion'])->toEqual(21_800)
        ->and(atAge($plan, 63)['conversion'])->toEqual(9_000);
});

it('only converts inside the window it is given', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 70, 'convert_until_age' => 71]);

    expect(collect($plan['rows'])->where('conversion', '>', 0)->pluck('age')->all())->toBe([70, 71]);
});

// Tax, IRMAA and heirs

it('taxes a conversion as ordinary income, in the year it is made', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 68, 'convert_until_age' => 68]);

    // $50,400 over the deduction: 10% of $12,400 and 12% of $38,000.
    expect(atAge($plan, 68)['tax'])->toEqual(5800)
        ->and(atAge($plan, 69)['tax'])->toEqual(0)
        // Paid from the brokerage account, so all of it reaches the Roth.
        ->and(atAge($plan, 68)['roth'])->toEqual(66_500)
        ->and(atAge($plan, 68)['taxable'])->toEqual(2_000_000 - 5800);
});

/**
 * The same one conversion of $66,500, and the same $5,800 of tax on it. What
 * differs is where the $5,800 comes from, and so how much reaches the Roth.
 */
it('takes the conversion\'s tax out of the converted money as the strategy says', function (array $settings, int $fromConversion) {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 68, 'convert_until_age' => 68, ...$settings]);

    expect(atAge($plan, 68)['conversion'])->toEqual(66_500)
        ->and(atAge($plan, 68)['tax'])->toEqual(5800)
        ->and(atAge($plan, 68)['conversion_tax'])->toEqual(5800)
        ->and(atAge($plan, 68)['conversion_tax_withheld'])->toEqual($fromConversion)
        ->and(atAge($plan, 68)['traditional'])->toEqual(1_000_000 - 66_500)
        ->and(atAge($plan, 68)['roth'])->toEqual(66_500 - $fromConversion)
        ->and(atAge($plan, 68)['taxable'])->toEqual(2_000_000 - (5800 - $fromConversion))
        ->and($plan['summary'])->toMatchArray(['conversion_tax' => 5800.0, 'conversion_tax_withheld' => (float) $fromConversion]);
})->with([
    'all from outside' => [['tax_payment' => 'outside'], 0],
    'all from the conversion' => [['tax_payment' => 'conversion'], 5800],
    'a quarter from outside' => [['tax_payment' => 'percent', 'tax_outside_amount' => 25], 4350],
    'the first $1,000 from outside' => [['tax_payment' => 'flat', 'tax_outside_amount' => 1000], 4800],
    'a flat amount that covers it all' => [['tax_payment' => 'flat', 'tax_outside_amount' => 9000], 0],
]);

it('counts only the tax the conversion adds, beside the tax the year owed anyway', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 30_000, 'frequency' => 'annual']);

    $row = atAge(runStrategy($user, 'fill_bracket', ['convert_until_age' => 68]), 68);

    // The pension alone owes $1,420; $36,500 converted at 12% adds $4,380.
    expect($row['tax'])->toEqual(5800)
        ->and($row['conversion_tax'])->toEqual(4380)
        ->and(atAge(runStrategy($user, 'none'), 68)['conversion_tax'])->toEqual(0);
});

/**
 * A million dollars of income in 2026 sets the premium for 2028: the top
 * tier, $487.00 more for Part B and $91.00 for Part D each month.
 */
it('charges IRMAA two years after the income that earned it', function () {
    $plan = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 68]);

    expect(atAge($plan, 68)['magi'])->toEqual(1_000_000)
        ->and(atAge($plan, 68)['irmaa'])->toEqual(0)
        ->and(atAge($plan, 70)['irmaa_tier'])->toBe(5)
        ->and(atAge($plan, 70)['irmaa'])->toEqual(round((487 + 91) * 12))
        ->and(atAge($plan, 71)['irmaa'])->toEqual(0)
        ->and($plan['summary'])->toMatchArray(['irmaa' => 6936.0, 'irmaa_years' => 1]);
});

it('charges IRMAA for each of two people on a joint return', function () {
    $user = conversionRetiree();
    Profile::query()->onlyOwnedBy($user)->update(['filing_status' => 'married_joint']);

    expect(atAge(runStrategy($user, 'lump', ['convert_from_age' => 68]), 70)['irmaa'])->toEqual(6936 * 2);
});

it('has an heir pay tax on inherited traditional money over ten years, on top of their own income', function () {
    $user = conversionRetiree();
    $plan = runStrategy($user, 'none', ['heir_income' => 100_000]);

    $left = $plan['summary']['ending_traditional'];
    $tax = new TaxCalculator;

    expect($left)->toBeGreaterThan(0)
        ->and($plan['summary']['heir_tax'])->toEqual(round(10 * ($tax->tax(100_000 + $left / 10, 'single') - $tax->tax(100_000, 'single'))))
        ->and($plan['summary']['tax_with_heirs'])->toEqual($plan['summary']['lifetime_tax'] + $plan['summary']['heir_tax'])
        ->and($plan['summary']['ending_after_heir_tax'])->toEqual($plan['summary']['ending_balance'] - $plan['summary']['heir_tax'])
        // The same inheritance costs a higher earner more.
        ->and(runStrategy($user, 'none', ['heir_income' => 400_000])['summary']['heir_tax'])->toBeGreaterThan($plan['summary']['heir_tax']);
});

it('leaves a charity nothing to pay', function () {
    $plan = runStrategy(conversionRetiree(), 'none', ['heir_is_charity' => true, 'heir_income' => 100_000]);

    expect($plan['summary']['heir_tax'])->toEqual(0)
        ->and($plan['summary']['tax_with_heirs'])->toEqual($plan['summary']['lifetime_tax']);
});

it('leaves heirs nothing to pay on money that was all converted', function () {
    expect(runStrategy(conversionRetiree(), 'lump', ['heir_income' => 100_000])['summary']['heir_tax'])->toEqual(0);
});

// What a strategy is run on

it('builds on the projection it names, and on the flows as entered when it names none', function () {
    $user = conversionRetiree();
    $pension = Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Optimistic']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $pension->id, 'overrides' => [2026 => 100_000]]);

    $onScenario = runStrategy($user, 'none', ['scenario_id' => $scenario->id]);

    expect(atAge(runStrategy($user, 'none'), 68)['bracket_income'])->toEqual(60_000)
        ->and(atAge($onScenario, 68)['bracket_income'])->toEqual(100_000)
        ->and(atAge($onScenario, 69)['bracket_income'])->toEqual(60_000)
        ->and($onScenario['assumptions']['scenario_name'])->toBe('Optimistic');
});

it('takes its inflation rate from the strategy, then the projection, then the profile', function () {
    $user = conversionRetiree();
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'bracket_inflation_rate' => 3]);

    expect(runStrategy($user, 'none', ['scenario_id' => $scenario->id, 'inflation_rate' => 4])['assumptions']['inflation_rate'])->toEqual(4)
        ->and(runStrategy($user, 'none', ['scenario_id' => $scenario->id])['assumptions']['inflation_rate'])->toEqual(3)
        ->and(runStrategy($user, 'none')['assumptions']['inflation_rate'])->toEqual(0);
});

/**
 * In today's dollars a bracket is the same line every year, whatever the
 * inflation rate: the tables and the dollars rise together.
 */
it('reports today\'s dollars, so the same bracket is filled to the same line every year', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'inflation_rate' => 5]);

    expect(atAge($plan, 68)['bracket_income'])->toEqual(66_500)
        ->and(atAge($plan, 72)['bracket_income'])->toEqual(66_500);
});

it('grows the balances at the fleet\'s own rate unless the strategy names one', function () {
    $user = conversionRetiree();
    Holding::query()->onlyOwnedBy($user)->update(['annual_rate' => 6]);

    $own = ConversionStrategy::factory()->create(['user_id' => $user->id]);

    expect(collect(app(ConversionBoard::class)->for($user)['strategies'])->firstWhere('id', $own->id)['assumptions']['growth_rate'])->toEqual(6)
        ->and(runStrategy($user, 'none', ['growth_rate' => 2])['assumptions']['growth_rate'])->toEqual(2);
});

it('says at what age the money runs out', function () {
    $user = conversionRetiree(brokerage: 0);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 200_000, 'frequency' => 'annual']);

    expect(runStrategy($user, 'none')['summary']['short_at_age'])->toBeGreaterThan(68)
        ->and(runStrategy(conversionRetiree(), 'none')['summary']['short_at_age'])->toBeNull();
});

it('falls back to the flows as entered when its projection is removed', function () {
    $user = conversionRetiree();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::factory()->create(['user_id' => $user->id, 'scenario_id' => $scenario->id]);

    $scenario->delete();

    expect($strategy->fresh()->scenario_id)->toBeNull();
});

// The page

it('gives the page the lines a year is charted against, and each strategy run', function () {
    $user = conversionRetiree();
    ConversionStrategy::factory()->ofKind('even')->create(['user_id' => $user->id, 'name' => 'Even']);
    ConversionStrategy::factory()->create(['name' => 'Somebody else\'s']);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Retirement')
            ->where('balances.deferred', 1_000_000)
            ->where('profile.age', 68)
            ->where('brackets.0', ['label' => '0%', 'value' => 16100])
            ->where('brackets.2', ['label' => '12%', 'value' => 66500])
            ->where('irmaa_tiers.0', ['label' => 'No surcharge', 'value' => 109000, 'surcharge_above' => 1148])
            ->where('defaults.even', [68, 72])
            ->where('defaults.fill_bracket_irmaa', [68, 80])
            ->where('defaults.none', null)
            ->has('strategies', 1)
            ->where('strategies.0.name', 'Even')
            ->where('strategies.0.kind_label', 'Even conversions before RMDs')
            ->has('strategies.0.rows', 13)
            ->has('strategies.0.summary.tax_with_heirs'));
});

it('keeps a placeholder tab, and has no others', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('finance.retirement', 'more'))->assertInertia(fn ($page) => $page->component('RetirementMore'));
    $this->actingAs($user)->get('/finance/retirement/annuities')->assertNotFound();
});

// Managing strategies

/**
 * @return array<string, mixed>
 */
function strategyPayload(array $overrides = []): array
{
    return [
        'name' => 'Fill the 22%',
        'kind' => 'fill_bracket',
        'scenario_id' => null,
        'convert_from_age' => null,
        'convert_until_age' => null,
        'fill_rate' => 22,
        'tax_payment' => 'outside',
        'tax_outside_amount' => null,
        'inflation_rate' => null,
        'growth_rate' => null,
        'heir_is_charity' => false,
        'heir_income' => 90_000,
        ...$overrides,
    ];
}

it('adds, edits, copies and removes a strategy', function () {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload())->assertSessionHasNoErrors();
    $strategy = ConversionStrategy::query()->onlyOwnedBy($user)->sole();
    expect($strategy->only(['name', 'kind', 'fill_rate', 'heir_income']))->toBe(['name' => 'Fill the 22%', 'kind' => 'fill_bracket', 'fill_rate' => 22.0, 'heir_income' => 90000.0]);

    $this->actingAs($user)->patch(route('finance.retirement.strategies.update', $strategy), strategyPayload(['name' => 'Fill the 24%', 'fill_rate' => 24, 'heir_is_charity' => true]))->assertSessionHasNoErrors();
    expect($strategy->fresh()->only(['name', 'fill_rate', 'heir_is_charity']))->toBe(['name' => 'Fill the 24%', 'fill_rate' => 24.0, 'heir_is_charity' => true]);

    $this->actingAs($user)->post(route('finance.retirement.strategies.duplicate', $strategy));
    expect(ConversionStrategy::query()->onlyOwnedBy($user)->where('name', 'Fill the 24% copy')->sole()->fill_rate)->toBe(24.0);

    $this->actingAs($user)->delete(route('finance.retirement.strategies.destroy', $strategy));
    expect($strategy->fresh())->toBeNull();
});

it('adds one strategy of each kind to start from', function () {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'));

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->orderBy('id')->pluck('kind')->all())->toBe(['none', 'lump', 'even', 'fill_bracket', 'fill_bracket_irmaa'])
        ->and(ConversionStrategy::query()->onlyOwnedBy($user)->pluck('heir_income')->unique()->all())->toBe([100000.0]);
});

it('refuses a strategy it could not run', function (array $overrides, string $field) {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload($overrides))->assertSessionHasErrors($field);

    expect(ConversionStrategy::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'a kind it does not know' => [['kind' => 'backdoor'], 'kind'],
    'a bracket that is not on the table' => [['fill_rate' => 15], 'fill_rate'],
    'a window entered back to front' => [['convert_from_age' => 72, 'convert_until_age' => 65], 'convert_until_age'],
    'inflation off the scale' => [['inflation_rate' => 40], 'inflation_rate'],
    'a way of paying the tax it does not know' => [['tax_payment' => 'later'], 'tax_payment'],
    'a split with no amount' => [['tax_payment' => 'percent'], 'tax_outside_amount'],
    'more than all of the tax from outside' => [['tax_payment' => 'percent', 'tax_outside_amount' => 150], 'tax_outside_amount'],
]);

it('will not build on someone else\'s projection', function () {
    $this->actingAs(conversionRetiree())
        ->post(route('finance.retirement.strategies.store'), strategyPayload(['scenario_id' => Scenario::factory()->create()->id]))
        ->assertSessionHasErrors('scenario_id');
});

it('hides another user\'s strategy behind a 404', function (string $method, string $route) {
    $strategy = ConversionStrategy::factory()->create(['name' => 'Theirs']);

    $this->actingAs(conversionRetiree())->{$method}(route($route, $strategy), strategyPayload())->assertNotFound();

    expect(ConversionStrategy::query()->pluck('name')->all())->toBe(['Theirs']);
})->with([
    'update' => ['patch', 'finance.retirement.strategies.update'],
    'duplicate' => ['post', 'finance.retirement.strategies.duplicate'],
    'destroy' => ['delete', 'finance.retirement.strategies.destroy'],
]);
