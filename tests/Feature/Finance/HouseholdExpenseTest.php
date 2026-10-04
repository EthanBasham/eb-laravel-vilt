<?php

use App\Models\Finance\Actual;
use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\User;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * A "Household expenses" flow estimated at $3,000 a month, with whatever
 * items are asked for inside it.
 *
 * @param  array<string, float>  $items  Name => monthly amount.
 */
function household(User $user, array $items = []): Flow
{
    $household = Flow::factory()->create(['user_id' => $user->id, 'category' => 'household', 'name' => 'Household expenses', 'amount' => 3000, 'is_essential' => true]);

    foreach ($items as $name => $amount) {
        Flow::factory()->create(['user_id' => $user->id, 'parent_id' => $household->id, 'category' => 'food', 'name' => $name, 'amount' => $amount]);
    }

    return $household;
}

/**
 * @return array<string, mixed>
 */
function itemPayload(array $overrides = []): array
{
    return ['direction' => 'expense', 'category' => 'food', 'name' => 'Groceries', 'amount' => 800, 'frequency' => 'monthly', 'annual_growth_rate' => 0, 'taxed_portion' => 100, 'is_essential' => true, ...$overrides];
}

// What it comes to

it('stands at its own estimate until it has items, and at their sum once it does', function () {
    $user = User::factory()->create();
    household($user);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page
            ->has('expenses', 1)
            ->where('expenses.0.items_count', 0)
            ->where('expenses.0.monthly_amount', 3000)
            ->where('cashflow.expenses', 3000));

    Flow::factory()->create(['user_id' => $user->id, 'parent_id' => Flow::query()->sole()->id, 'category' => 'food', 'amount' => 800]);
    Flow::factory()->create(['user_id' => $user->id, 'parent_id' => Flow::query()->oldest('id')->first()->id, 'category' => 'utilities', 'amount' => 1200, 'frequency' => 'quarterly']);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page
            // Still one line: the items are set up on the budget.
            ->has('expenses', 1)
            ->where('expenses.0.items_count', 2)
            ->where('expenses.0.monthly_amount', 1200)
            ->where('cashflow.expenses', 1200));
});

// Setting it up on the budget

it('lists a household expense on the budget apart from the other expenses, item by item', function () {
    $user = User::factory()->create();
    $household = household($user, ['Groceries' => 800, 'Utilities' => 300]);
    Flow::factory()->create(['user_id' => $user->id, 'name' => 'Netflix', 'amount' => 20]);
    Actual::query()->create(['user_id' => $user->id, 'flow_id' => $household->children->first()->id, 'month' => '2026-10-01', 'amount' => 900]);

    $this->actingAs($user)->get(route('finance.budget'))
        ->assertInertia(fn ($page) => $page
            ->has('expenses', 1)
            ->where('expenses.0.name', 'Netflix')
            ->has('households', 1)
            ->where('households.0.name', 'Household expenses')
            ->where('households.0.is_itemised', true)
            ->where('households.0.planned', 1100)
            ->where('households.0.actual', 900)
            ->has('households.0.items', 2)
            ->where('households.0.items.0.name', 'Groceries')
            ->where('households.0.items.0.variance', -100)
            // Every dollar once: the two items and the subscription.
            ->where('totals.planned_expenses', 1120)
            ->where('totals.rows', 3));
});

it('budgets a household expense with no items as its one estimate', function () {
    $user = User::factory()->create();
    household($user);

    $this->actingAs($user)->get(route('finance.budget'))
        ->assertInertia(fn ($page) => $page
            ->has('expenses', 0)
            ->where('households.0.is_itemised', false)
            ->where('households.0.planned', 3000)
            ->has('households.0.items', 0)
            ->where('totals.planned_expenses', 3000));
});

it('adds an item inside a household expense, belonging to nothing else', function () {
    $user = User::factory()->create();
    $household = household($user);

    $this->actingAs($user)->post(route('finance.flows.store'), itemPayload(['parent_id' => $household->id]))->assertSessionHasNoErrors();

    $item = $household->children()->sole();
    expect($item->only(['name', 'category', 'holding_id', 'armada_id']))->toBe(['name' => 'Groceries', 'category' => 'food', 'holding_id' => null, 'armada_id' => null]);
});

it('refuses an item it cannot nest', function (Closure $payload, string $field, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.flows.store'), $payload($user))->assertSessionHasErrors([$field => $message]);
})->with([
    'inside a flow that cannot hold items' => [
        fn (User $user) => itemPayload(['parent_id' => Flow::factory()->create(['user_id' => $user->id, 'name' => 'Netflix'])->id]),
        'parent_id', 'Netflix cannot hold items.',
    ],
    'an item that would itself hold items' => [
        fn (User $user) => itemPayload(['parent_id' => household($user)->id, 'category' => 'household']),
        'category', 'An item cannot itself hold items. Pick an ordinary category.',
    ],
    'inside an item' => [
        fn (User $user) => itemPayload(['parent_id' => household($user, ['Groceries' => 800])->children->first()->id]),
        'parent_id', 'The selected parent id is invalid.',
    ],
    'inside another user\'s flow' => [
        fn (User $user) => itemPayload(['parent_id' => household(User::factory()->create())->id]),
        'parent_id', 'The selected parent id is invalid.',
    ],
]);

it('will not recategorise a household expense out from under its items', function () {
    $user = User::factory()->create();
    $household = household($user, ['Groceries' => 800]);

    $this->actingAs($user)->patch(route('finance.flows.update', $household), itemPayload(['category' => 'food', 'name' => 'Household expenses']))
        ->assertSessionHasErrors(['category' => 'Move or remove the items inside this one before changing its category.']);

    expect($household->fresh()->category)->toBe('household');
});

it('removes the items along with the household expense they are in', function () {
    $user = User::factory()->create();
    $household = household($user, ['Groceries' => 800]);

    $this->actingAs($user)->delete(route('finance.flows.destroy', $household))->assertRedirect();

    expect(Flow::query()->count())->toBe(0);
});

// Projecting

it('projects a household expense as one group that opens onto its items', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1976-03-01', 'retirement_age' => 65, 'life_expectancy' => 90]);
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    household($user, ['Groceries' => 800, 'Utilities' => 300]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->has('expenses', 1)
            ->where('expenses.0.is_group', true)
            ->where('expenses.0.name', 'Household expenses')
            ->where('expenses.0.series.0.amount', 13200)
            ->has('expenses.0.items', 2)
            ->where('expenses.0.items.0.name', 'Groceries')
            ->where('expenses.0.items.0.series.0.amount', 9600)
            // Counted once in the year's total, not once as a group and again as items.
            ->where('totals.0.expenses', 13200));
});

it('grows every item at the rate the scenario gives the group, except one with a rate of its own', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1976-03-01', 'retirement_age' => 65, 'life_expectancy' => 90]);
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $household = household($user, ['Groceries' => 1000, 'Utilities' => 1000]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $household->id, 'annual_growth_rate' => 10]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $household->children->last()->id, 'annual_growth_rate' => 50]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('expenses.0.rate', 10)
            ->where('expenses.0.items.0.rate', 10)
            ->where('expenses.0.items.0.has_scenario_rate', false)
            ->where('expenses.0.items.0.series.1.amount', 13200)
            ->where('expenses.0.items.1.rate', 50)
            ->where('expenses.0.items.1.series.1.amount', 18000)
            ->where('expenses.0.series.1.amount', 31200));
});
