<?php

use App\Models\Finance\Actual;
use App\Models\Finance\Flow;
use App\Models\Finance\Goal;
use App\Models\Finance\Holding;
use App\Models\User;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * @return array<string, mixed>
 */
function flowPayload(array $overrides = []): array
{
    return [
        'direction' => 'expense',
        'category' => 'subscriptions',
        'name' => 'Netflix',
        'amount' => 22.99,
        'frequency' => 'monthly',
        'hours_per_week' => null,
        'annual_growth_rate' => 0,
        'taxation' => null,
        'taxed_portion' => 100,
        'is_essential' => false,
        'starts_on' => null,
        'ends_on' => null,
        'holding_id' => null,
        ...$overrides,
    ];
}

it('totals the monthly run rate of income against expenses', function () {
    $user = User::factory()->create();
    Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 2000, 'frequency' => 'biweekly']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 1200, 'frequency' => 'annual']);
    Flow::factory()->income()->create(['amount' => 99999]);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Cashflow')
            ->has('income', 1)
            ->has('expenses', 1)
            ->where('cashflow.income', 4333.33)
            // $52,000 of W-2 wages, single: $4,060 of income tax on the
            // $35,900 over the deduction, and 7.65% FICA on all of it.
            ->where('cashflow.taxes', 669.83)
            ->where('cashflow.tax_rate', 15.5)
            ->where('cashflow.expenses', 100)
            ->where('cashflow.net', 3563.5));
});

it('takes no tax from income that is not taxed', function () {
    $user = User::factory()->create();
    Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 5000, 'taxation' => null]);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page
            ->where('income.0.taxation', null)
            ->where('cashflow.taxes', 0)
            ->where('cashflow.net', 5000));
});

/**
 * $66,500 of pension would be $5,800 of tax, a month's share $483.33. Taxed
 * on half of it, $33,250 is $17,150 over the deduction: $1,810, or $150.83.
 */
it('taxes only the portion of an income that is set to be taxed', function () {
    $user = User::factory()->create();
    $pension = Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 66500, 'frequency' => 'annual']);

    $this->actingAs($user)->get(route('finance.cashflow'))->assertInertia(fn ($page) => $page->where('cashflow.taxes', 483.33));

    $pension->update(['taxed_portion' => 50]);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page->where('income.0.taxed_portion', 50)->where('cashflow.taxes', 150.83));
});

it('refuses a taxed portion over the whole of an income', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('finance.flows.store'), flowPayload(['direction' => 'income', 'category' => 'social_security', 'taxation' => 'income_only', 'taxed_portion' => 120]))
        ->assertSessionHasErrors('taxed_portion');
});

it('saves how an income is taxed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.flows.store'), flowPayload(['direction' => 'income', 'category' => 'business', 'name' => 'S-corp profit', 'taxation' => 'income_only', 'taxed_portion' => 85]))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page
            ->where('income.0.taxation', 'income_only')
            ->where('income.0.taxed_portion', 85)
            ->where('income.0.taxation_label', 'Income tax only'));
});

it('refuses a tax treatment it does not know, or one on an expense', function (array $overrides) {
    $this->actingAs(User::factory()->create())->post(route('finance.flows.store'), flowPayload($overrides))->assertSessionHasErrors('taxation');

    expect(Flow::query()->count())->toBe(0);
})->with([
    'unknown' => [['direction' => 'income', 'category' => 'salary', 'taxation' => 'offshore']],
    'on an expense' => [['taxation' => 'w2']],
]);

it('adds a flow', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.flows.store'), flowPayload())->assertSessionHasNoErrors();

    expect(Flow::query()->onlyOwnedBy($user)->sole())->name->toBe('Netflix')->amount->toBe(22.99);
});

it('refuses a flow it cannot make sense of', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('finance.flows.store'), flowPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'an income category on an expense' => [['category' => 'salary'], 'category'],
    'an unknown frequency' => [['frequency' => 'fortnightly'], 'frequency'],
    'an hourly rate with no hours' => [['frequency' => 'hourly'], 'hours_per_week'],
    'an end before its start' => [['starts_on' => '2027-01-01', 'ends_on' => '2026-01-01'], 'ends_on'],
    'a negative amount' => [['amount' => -5], 'amount'],
]);

it('hangs a flow off one of the user\'s own holdings only', function () {
    $user = User::factory()->create();
    $theirs = Holding::factory()->create();

    $this->actingAs($user)
        ->post(route('finance.flows.store'), flowPayload(['holding_id' => $theirs->id]))
        ->assertSessionHasErrors('holding_id');
});

it('will not change or remove another user\'s flow', function (string $method, string $route) {
    $flow = Flow::factory()->create(['name' => 'Theirs']);

    $this->actingAs(User::factory()->create())->{$method}(route($route, $flow), flowPayload())->assertNotFound();

    expect($flow->fresh()->name)->toBe('Theirs');
})->with([
    ['patch', 'finance.flows.update'],
    ['delete', 'finance.flows.destroy'],
]);

// Budget

it('budgets each flow at its monthly amount and splits needs from wants', function () {
    $user = User::factory()->create();
    Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 5000]);
    Flow::factory()->create(['user_id' => $user->id, 'category' => 'housing', 'amount' => 2000, 'is_essential' => true]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 500]);
    // Not started yet, so not in this month's budget.
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 900, 'starts_on' => '2040-01-01']);

    $this->actingAs($user)->get(route('finance.budget'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Budget')
            ->where('month', '2026-10')
            ->has('income', 1)
            ->where('totals.planned_income', 5000)
            ->where('totals.planned_expenses', 2500)
            ->where('totals.planned_net', 2500)
            ->where('split.0.share', 40)
            ->where('split.1.share', 10)
            ->where('split.2.share', 50));
});

it('shows this month when the month asked for is unreadable', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('finance.budget', ['month' => 'not-a-month']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('month', '2026-10'));
});

it('records an actual, overwrites it, and reports the difference', function () {
    $user = User::factory()->create();
    $flow = Flow::factory()->create(['user_id' => $user->id, 'amount' => 400]);

    $this->actingAs($user)->put(route('finance.budget.actual', $flow), ['month' => '2026-10', 'amount' => 999]);
    $this->actingAs($user)->put(route('finance.budget.actual', $flow), ['month' => '2026-10', 'amount' => 450])->assertSessionHasNoErrors();

    expect(Actual::query()->count())->toBe(1);

    // Overspent by 50, and for an expense that is bad news: negative.
    $this->actingAs($user)->get(route('finance.budget', ['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page
            ->where('expenses.0.actual', 450)
            ->where('expenses.0.variance', -50)
            ->where('totals.tracked', 1));
});

/**
 * An emptied field is "forget this", which is not the same as a month where
 * the bill really was zero.
 */
it('clears an actual when sent null, and keeps one sent as zero', function () {
    $user = User::factory()->create();
    $flow = Flow::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.budget.actual', $flow), ['month' => '2026-10', 'amount' => 0]);
    expect(Actual::query()->sole()->amount)->toBe(0.0);

    $this->actingAs($user)->put(route('finance.budget.actual', $flow), ['month' => '2026-10', 'amount' => null]);
    expect(Actual::query()->count())->toBe(0);
});

it('will not record an actual against another user\'s flow', function () {
    $flow = Flow::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('finance.budget.actual', $flow), ['month' => '2026-10', 'amount' => 5])
        ->assertNotFound();

    expect(Actual::query()->count())->toBe(0);
});

// Goals

it('adds a goal and plans it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.goals.store'), [
        'name' => 'Car', 'target_amount' => 12000, 'saved_amount' => 0, 'target_date' => '2027-10-01',
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.goals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Goals')
            ->has('goals', 1)
            ->where('goals.0.months_remaining', 12)
            ->where('goals.0.strategies.0.monthly', 1000)
            ->has('goals.0.strategies', 4));
});

it('refuses a goal with no target', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('finance.goals.store'), ['name' => 'Car', 'target_amount' => 0, 'saved_amount' => 0, 'target_date' => '2027-10-01'])
        ->assertSessionHasErrors('target_amount');
});

it('will not change or remove another user\'s goal', function (string $method, string $route) {
    $goal = Goal::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Theirs', 'target_amount' => 100, 'target_date' => '2027-01-01']);

    $this->actingAs(User::factory()->create())
        ->{$method}(route($route, $goal), ['name' => 'Mine', 'target_amount' => 1, 'saved_amount' => 0, 'target_date' => '2027-01-01'])
        ->assertNotFound();

    expect($goal->fresh()->name)->toBe('Theirs');
})->with([
    ['patch', 'finance.goals.update'],
    ['delete', 'finance.goals.destroy'],
]);

/**
 * A pension that starts in 2040 is worth listing and not worth counting: the
 * run rate is what is arriving now.
 */
it('leaves income that has not started out of the monthly run rate', function () {
    $user = User::factory()->create();
    Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 5000]);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 1400, 'starts_on' => '2040-06-01']);
    Flow::factory()->income('contract')->create(['user_id' => $user->id, 'amount' => 3200, 'frequency' => 'once']);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page
            ->has('income', 3)
            ->where('cashflow.income', 5000)
            // Listed by category: the one-time contract, the pension, the salary.
            ->where('income.1.name', fn (string $name) => $name !== '')
            ->where('income.1.category', 'pension')
            ->where('income.1.is_running', false)
            ->where('income.2.is_running', true));
});
