<?php

use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\WithdrawalStrategy;
use App\Models\User;
use App\Services\Finance\WithdrawalBoard;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * A single retiree of 66, planning to 67 — two years, 2026 and 2027 — with
 * no inflation, no growth and no income, and whatever is asked for in each
 * of the three buckets. RMDs are years off (75), and at 66 the standard
 * deduction is $16,100 plus $2,050.
 *
 * @param  array{taxable?: float, traditional?: float, roth?: float}  $balances
 */
function retiree(float $monthlyExpenses = 2000, array $balances = []): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1960-01-01', 'retirement_age' => 65, 'life_expectancy' => 67, 'inflation_rate' => 0, 'filing_status' => 'single']);

    Holding::factory()->create(['user_id' => $user->id, 'balance' => $balances['taxable'] ?? 100000, 'annual_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => $balances['traditional'] ?? 100000, 'annual_rate' => 0]);
    Holding::factory()->retirement('roth', 'ira')->create(['user_id' => $user->id, 'balance' => $balances['roth'] ?? 100000, 'annual_rate' => 0]);

    Flow::factory()->create(['user_id' => $user->id, 'amount' => $monthlyExpenses]);

    return $user;
}

/**
 * The strategy as the withdrawals tab returns it.
 *
 * @return array<string, mixed>
 */
function withdrawn(User $user, WithdrawalStrategy $strategy): array
{
    return app(WithdrawalBoard::class)->for($user)['strategies']->firstWhere('id', $strategy->id);
}

// The order

it('draws a year\'s expenses from the buckets in the strategy\'s order', function (string $kind, array $first) {
    $user = retiree();
    $strategy = WithdrawalStrategy::factory()->ofKind($kind)->create(['user_id' => $user->id]);

    expect(withdrawn($user, $strategy)['rows'][0])->toMatchArray($first);
})->with([
    'savings first' => ['conventional', ['from_taxable' => 24000.0, 'from_traditional' => 0.0, 'from_roth' => 0.0, 'tax' => 0.0]],
    // Traditional money is income: T = 24,000 + 10% of (T − 18,150), so 24,650 and $650 of tax.
    'traditional first' => ['traditional_first', ['from_taxable' => 0.0, 'from_traditional' => 24650.0, 'from_roth' => 0.0, 'tax' => 650.0]],
    'Roth first' => ['roth_first', ['from_taxable' => 0.0, 'from_traditional' => 0.0, 'from_roth' => 24000.0, 'tax' => 0.0]],
    // A third from each; 8,000 of traditional is inside the deduction.
    'in proportion' => ['proportional', ['from_taxable' => 8000.0, 'from_traditional' => 8000.0, 'from_roth' => 8000.0, 'tax' => 0.0]],
]);

it('moves on to the next bucket when one runs out, and says when they all have', function () {
    $user = retiree(2000, ['taxable' => 10000, 'traditional' => 0, 'roth' => 20000]);
    $strategy = WithdrawalStrategy::factory()->ofKind('conventional')->create(['user_id' => $user->id]);

    $result = withdrawn($user, $strategy);

    expect($result['rows'][0])->toMatchArray(['from_taxable' => 10000.0, 'from_roth' => 14000.0, 'unmet' => 0.0])
        ->and($result['rows'][1])->toMatchArray(['from_taxable' => 0.0, 'from_roth' => 6000.0, 'unmet' => 18000.0, 'spendable' => 6000.0])
        ->and($result['summary']['short_at_age'])->toBe(67)
        ->and($result['summary']['ending_balance'])->toBe(0.0);
});

it('takes traditional money only to the top of the bracket it fills, then savings', function () {
    $user = retiree(4000);
    $strategy = WithdrawalStrategy::factory()->ofKind('bracket_fill', ['fill_rate' => 10])->create(['user_id' => $user->id]);

    // The 10% bracket tops out at 12,400 of taxable income: 30,550 with the
    // deduction, taxed $1,240. Savings cover the rest of 48,000 and the tax.
    expect(withdrawn($user, $strategy)['rows'][0])->toMatchArray(['from_traditional' => 30550.0, 'from_taxable' => 18690.0, 'from_roth' => 0.0, 'tax' => 1240.0, 'marginal_rate' => 10.0]);
});

// How much a year takes

it('takes a fixed amount whatever the year needs, and leaves what that gives to spend', function () {
    $user = retiree();
    $strategy = WithdrawalStrategy::factory()->ofKind('conventional', ['spending_rule' => 'fixed', 'spending_amount' => 40000])->create(['user_id' => $user->id]);

    $result = withdrawn($user, $strategy);

    expect($result['rows'][0])->toMatchArray(['from_taxable' => 40000.0, 'spendable' => 40000.0, 'expenses' => 24000.0])
        ->and($result['summary'])->toMatchArray(['withdrawn' => 80000.0, 'ending_taxable' => 20000.0, 'least_spendable' => 40000.0]);
});

it('takes a share of whatever is left each year', function () {
    $user = retiree();
    $strategy = WithdrawalStrategy::factory()->ofKind('conventional', ['spending_rule' => 'percent', 'spending_percent' => 4])->create(['user_id' => $user->id]);

    $rows = withdrawn($user, $strategy)['rows'];

    // 4% of 300,000, then 4% of the 288,000 that leaves.
    expect($rows[0]['from_taxable'])->toBe(12000.0)
        ->and($rows[1]['from_taxable'])->toBe(11520.0);
});

it('saves a working year\'s surplus and pays its contributions in, whatever the rule', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1976-01-01', 'retirement_age' => 65, 'life_expectancy' => 51, 'inflation_rate' => 0, 'filing_status' => 'single']);
    Holding::factory()->create(['user_id' => $user->id, 'balance' => 1000, 'annual_rate' => 0, 'monthly_contribution' => 100]);
    Flow::factory()->income('other_income')->create(['user_id' => $user->id, 'taxation' => null, 'amount' => 1000]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 500]);
    $strategy = WithdrawalStrategy::factory()->ofKind('conventional', ['spending_rule' => 'fixed', 'spending_amount' => 99999])->create(['user_id' => $user->id]);

    // 12,000 in, 6,000 spent: 1,200 of the surplus is the contribution and
    // the other 4,800 is saved beside it.
    expect(withdrawn($user, $strategy)['rows'][0])->toMatchArray(['is_retired' => false, 'withdrawn' => 0.0, 'taxable' => 7000.0]);
});

// The page

it('shows the tab with the buckets and each strategy\'s result', function () {
    $user = retiree();
    WithdrawalStrategy::factory()->ofKind('traditional_first')->create(['user_id' => $user->id, 'name' => 'Drain the IRA']);

    $this->actingAs($user)->get(route('finance.retirement', 'withdrawals'))
        ->assertInertia(fn ($page) => $page
            ->component('Withdrawals')
            ->where('balances', ['deferred' => 100000, 'free' => 100000, 'taxable' => 100000, 'total' => 300000])
            ->where('default_spending_amount', 12000)
            ->has('kinds', 5)
            ->has('spending_rules', 3)
            ->has('strategies', 1)
            ->where('strategies.0.name', 'Drain the IRA')
            ->where('strategies.0.kind_label', 'Traditional first')
            ->has('strategies.0.rows', 2)
            ->where('strategies.0.summary.lifetime_tax', 1300));
});

// Managing

/**
 * @return array<string, mixed>
 */
function withdrawalPayload(array $overrides = []): array
{
    return ['name' => 'Mine', 'kind' => 'conventional', 'scenario_id' => null, 'fill_rate' => null, 'spending_rule' => 'projection', 'spending_amount' => null, 'spending_percent' => null, 'inflation_rate' => null, 'growth_rate' => null, ...$overrides];
}

it('adds, changes, copies and removes a strategy', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.retirement.withdrawals.store'), withdrawalPayload())->assertSessionHasNoErrors();

    $strategy = WithdrawalStrategy::query()->sole();
    expect($strategy->only(['user_id', 'name', 'kind', 'spending_rule']))->toBe(['user_id' => $user->id, 'name' => 'Mine', 'kind' => 'conventional', 'spending_rule' => 'projection']);

    $this->actingAs($user)->patch(route('finance.retirement.withdrawals.update', $strategy), withdrawalPayload(['spending_rule' => 'percent', 'spending_percent' => 3.5]))->assertSessionHasNoErrors();

    expect($strategy->fresh()->only(['spending_rule', 'spending_percent']))->toBe(['spending_rule' => 'percent', 'spending_percent' => 3.5]);

    $this->actingAs($user)->post(route('finance.retirement.withdrawals.duplicate', $strategy))->assertRedirect();

    expect(WithdrawalStrategy::query()->latest('id')->first()->only(['name', 'spending_percent']))->toBe(['name' => 'Mine copy', 'spending_percent' => 3.5]);

    $this->actingAs($user)->delete(route('finance.retirement.withdrawals.destroy', $strategy))->assertRedirect();

    $this->assertModelMissing($strategy);
});

it('refuses a strategy it cannot run', function (array $overrides, string $field, string $message) {
    $this->actingAs(User::factory()->create())
        ->post(route('finance.retirement.withdrawals.store'), withdrawalPayload($overrides))
        ->assertSessionHasErrors([$field => $message]);

    expect(WithdrawalStrategy::query()->count())->toBe(0);
})->with([
    'an order it does not know' => [['kind' => 'lottery'], 'kind', 'The selected kind is invalid.'],
    'a fixed amount with no amount' => [['spending_rule' => 'fixed'], 'spending_amount', 'Say how much to take each year.'],
    'a percentage with no percentage' => [['spending_rule' => 'percent'], 'spending_percent', 'Say what share of the balance to take each year.'],
    'a bracket that is not on the table' => [['kind' => 'bracket_fill', 'fill_rate' => 15], 'fill_rate', 'The selected fill rate is invalid.'],
]);

it('builds on one of the user\'s own projections only', function () {
    $theirs = Scenario::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('finance.retirement.withdrawals.store'), withdrawalPayload(['scenario_id' => $theirs->id]))
        ->assertSessionHasErrors('scenario_id');
});

it('starts with one strategy for each order', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.retirement.withdrawals.starters'))->assertRedirect();

    expect(WithdrawalStrategy::query()->onlyOwnedBy($user)->pluck('kind')->all())->toBe(['conventional', 'traditional_first', 'roth_first', 'proportional', 'bracket_fill']);
});

it('will not change, copy or remove another user\'s strategy', function (string $method, string $route) {
    $strategy = WithdrawalStrategy::factory()->create(['name' => 'Theirs']);

    $this->actingAs(User::factory()->create())->{$method}(route($route, $strategy), withdrawalPayload())->assertNotFound();

    expect(WithdrawalStrategy::query()->pluck('name')->all())->toBe(['Theirs']);
})->with([
    ['patch', 'finance.retirement.withdrawals.update'],
    ['post', 'finance.retirement.withdrawals.duplicate'],
    ['delete', 'finance.retirement.withdrawals.destroy'],
]);
