<?php

use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Position;
use App\Models\User;

/**
 * @return array<string, mixed>
 */
function holdingPayload(array $overrides = []): array
{
    return [
        'type' => 'brokerage',
        'name' => 'Joint brokerage',
        'institution' => null,
        'balance' => 12500,
        'annual_rate' => 7,
        'monthly_contribution' => 250,
        'secured_by_id' => null,
        'notes' => null,
        ...$overrides,
    ];
}

it('sends a guest to sign in', function () {
    $this->get(route('finance.fleet'))->assertRedirect(route('login'));
});

it('lists the signed-in user\'s holdings on their side of the balance sheet', function () {
    $user = User::factory()->create();
    Holding::factory()->create(['user_id' => $user->id, 'name' => 'Mine', 'balance' => 9000]);
    Holding::factory()->liability('credit_card')->create(['user_id' => $user->id, 'name' => 'Card', 'balance' => 1000]);
    Holding::factory()->create(['name' => 'Someone else\'s']);

    $this->actingAs($user)->get(route('finance.fleet'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Fleet')
            ->has('assets', 1)
            ->where('assets.0.name', 'Mine')
            ->has('liabilities', 1)
            ->where('liabilities.0.name', 'Card')
            ->where('totals.net_worth', 8000));
});

it('adds a holding, taking its side from its type', function (string $type, string $side) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.fleet.store'), holdingPayload(['type' => $type]))->assertRedirect();

    $holding = Holding::query()->onlyOwnedBy($user)->sole();

    expect($holding->side)->toBe($side)
        ->and($holding->balance)->toBe(12500.0);
})->with([
    ['brokerage', 'asset'],
    ['mortgage', 'liability'],
]);

/**
 * A client cannot post its way onto the wrong side: `side` is not an accepted
 * field at all, so a mortgage stays a liability whatever arrives with it.
 */
it('ignores a posted side', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.fleet.store'), holdingPayload(['type' => 'mortgage', 'side' => 'asset']));

    expect(Holding::query()->onlyOwnedBy($user)->sole()->side)->toBe('liability');
});

it('refuses a holding it cannot make sense of', function (array $overrides, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.fleet.store'), holdingPayload($overrides))->assertSessionHasErrors($field);

    expect(Holding::query()->onlyOwnedBy($user)->count())->toBe(0);
})->with([
    'an unknown type' => [['type' => 'yacht'], 'type'],
    'no name' => [['name' => ''], 'name'],
    'a negative balance' => [['balance' => -1], 'balance'],
    'a rate past 100%' => [['annual_rate' => 250], 'annual_rate'],
]);

it('secures a debt against one of the user\'s own assets only', function () {
    $user = User::factory()->create();
    $mine = Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id]);
    $theirs = Holding::factory()->ofType('real_estate')->create();

    $this->actingAs($user)
        ->post(route('finance.fleet.store'), holdingPayload(['type' => 'mortgage', 'secured_by_id' => $theirs->id]))
        ->assertSessionHasErrors('secured_by_id');

    $this->actingAs($user)
        ->post(route('finance.fleet.store'), holdingPayload(['type' => 'mortgage', 'secured_by_id' => $mine->id]))
        ->assertSessionHasNoErrors();

    expect(Holding::query()->onlyOwnedBy($user)->where('type', 'mortgage')->sole()->secured_by_id)->toBe($mine->id);
});

it('shows a property\'s equity and the cash it throws off', function () {
    $user = User::factory()->create();
    $duplex = Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'balance' => 400000]);
    Holding::factory()->liability('mortgage')->create(['user_id' => $user->id, 'balance' => 250000, 'secured_by_id' => $duplex->id]);
    Flow::factory()->income('rental')->create(['user_id' => $user->id, 'holding_id' => $duplex->id, 'amount' => 3000]);
    Flow::factory()->create(['user_id' => $user->id, 'holding_id' => $duplex->id, 'category' => 'insurance', 'amount' => 1200, 'frequency' => 'annual']);

    $this->actingAs($user)->get(route('finance.fleet.show', $duplex))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Holding')
            ->where('summary.equity', 150000)
            ->where('summary.monthly_income', 3000)
            ->where('summary.monthly_expenses', 100)
            ->where('summary.monthly_net', 2900)
            ->has('flows', 2)
            ->has('secured_debts', 1));
});

it('updates a holding', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('finance.fleet.update', $holding), holdingPayload(['name' => 'Renamed', 'balance' => 77]))->assertRedirect();

    expect($holding->fresh())->name->toBe('Renamed')->balance->toBe(77.0);
});

it('removes a holding along with its positions and flows', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->create(['user_id' => $user->id]);
    $holding->positions()->create(['name' => 'Fund', 'asset_class' => 'etf', 'value' => 100]);
    Flow::factory()->create(['user_id' => $user->id, 'holding_id' => $holding->id]);

    $this->actingAs($user)->delete(route('finance.fleet.destroy', $holding))->assertRedirect(route('finance.fleet'));

    expect(Holding::query()->count())->toBe(0)
        ->and(Position::query()->count())->toBe(0)
        ->and(Flow::query()->count())->toBe(0);
});

it('hides another user\'s holding from every route that takes one', function (string $method, string $route) {
    $holding = Holding::factory()->create(['name' => 'Private']);

    $this->actingAs(User::factory()->create())
        ->{$method}(route($route, $holding), holdingPayload())
        ->assertNotFound();

    expect($holding->fresh()->name)->toBe('Private');
})->with([
    ['get', 'finance.fleet.show'],
    ['patch', 'finance.fleet.update'],
    ['delete', 'finance.fleet.destroy'],
]);

// Positions

it('itemises an account, which then takes its value from its positions', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->create(['user_id' => $user->id, 'balance' => 1]);

    $this->actingAs($user)->post(route('finance.positions.store', $holding), [
        'name' => 'Total Market ETF', 'symbol' => 'TMKT', 'asset_class' => 'etf', 'value' => 8000, 'expected_return' => 7, 'expense_ratio' => 0.03,
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.fleet.show', $holding))
        ->assertInertia(fn ($page) => $page->where('holding.value', 8000)->has('positions', 1));
});

it('will not touch a position inside another user\'s account', function (string $method, string $route) {
    $position = Holding::factory()->create()->positions()->create(['name' => 'Theirs', 'asset_class' => 'etf', 'value' => 100]);

    $this->actingAs(User::factory()->create())
        ->{$method}(route($route, $position), ['name' => 'Mine now', 'asset_class' => 'etf', 'value' => 1, 'expected_return' => 1, 'expense_ratio' => 0])
        ->assertNotFound();

    expect($position->fresh()->name)->toBe('Theirs');
})->with([
    ['patch', 'finance.positions.update'],
    ['delete', 'finance.positions.destroy'],
]);

it('will not add a position to another user\'s account', function () {
    $holding = Holding::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('finance.positions.store', $holding), ['name' => 'X', 'asset_class' => 'etf', 'value' => 1, 'expected_return' => 1, 'expense_ratio' => 0])
        ->assertNotFound();

    expect($holding->positions()->count())->toBe(0);
});

/**
 * An account that holds others has no balance of its own, so ordering by the
 * balance column alone would put the largest thing in the fleet last.
 */
it('lists holdings largest first by what they are worth, not by their own balance', function () {
    $user = User::factory()->create();
    Holding::factory()->create(['user_id' => $user->id, 'name' => 'Savings', 'balance' => 9000]);
    $plan = Holding::factory()->create(['user_id' => $user->id, 'name' => 'Work plan', 'balance' => 0]);
    Holding::factory()->inside($plan)->create(['user_id' => $user->id, 'balance' => 50000]);

    $this->actingAs($user)->get(route('finance.fleet'))
        ->assertInertia(fn ($page) => $page
            ->where('assets.0.name', 'Work plan')
            ->where('assets.1.name', 'Savings'));
});
