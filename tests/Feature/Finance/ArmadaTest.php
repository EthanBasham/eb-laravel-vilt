<?php

use App\Models\Finance\Armada;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\User;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

// What an armada comes to

it('adds up the holdings and flows in each armada, and lists what is in none', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id, 'name' => 'Real estate']);
    Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'balance' => 300000]);
    Holding::factory()->liability()->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'balance' => 200000]);
    Holding::factory()->create(['user_id' => $user->id, 'name' => 'Loose brokerage', 'balance' => 5000]);
    Flow::factory()->income('rental')->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'amount' => 2000]);
    Flow::factory()->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'amount' => 500]);

    $this->actingAs($user)->get(route('finance.armadas'))
        ->assertInertia(fn ($page) => $page
            ->component('Armadas')
            ->has('armadas', 1)
            ->where('armadas.0.name', 'Real estate')
            ->where('armadas.0.totals', ['assets' => 300000, 'liabilities' => 200000, 'net_worth' => 100000])
            ->where('armadas.0.cashflow', ['income' => 2000, 'expenses' => 500, 'net' => 1500])
            ->has('unassigned.holdings', 1)
            ->where('unassigned.holdings.0.name', 'Loose brokerage'));
});

it('puts a flow in its holding\'s armada, whatever its own says', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    $other = Armada::factory()->create(['user_id' => $user->id]);
    $duplex = Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'armada_id' => $armada->id]);
    Flow::factory()->income('rental')->create(['user_id' => $user->id, 'holding_id' => $duplex->id, 'armada_id' => $other->id, 'name' => 'Rent', 'amount' => 1500]);

    $this->actingAs($user)->get(route('finance.armadas.show', $armada))
        ->assertInertia(fn ($page) => $page
            ->component('Armada')
            ->has('income', 1)
            ->where('income.0.name', 'Rent')
            ->where('cashflow.income', 1500));
});

it('keeps an account inside another in the outer account\'s armada', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    $ira = Holding::factory()->retirement('roth', 'ira')->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'balance' => 0]);
    Holding::factory()->inside($ira)->create(['balance' => 40000]);

    $this->actingAs($user)->get(route('finance.armadas.show', $armada))
        ->assertInertia(fn ($page) => $page
            ->has('assets', 1)
            ->where('totals.assets', 40000));
});

it('projects an armada\'s own income, expenses and holdings year by year', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    Holding::factory()->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'balance' => 10000, 'annual_rate' => 10]);
    Holding::factory()->create(['user_id' => $user->id, 'balance' => 99999]);
    Flow::factory()->income('rental')->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'amount' => 1000]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 777]);

    $this->actingAs($user)->get(route('finance.armadas.show', $armada))
        ->assertInertia(fn ($page) => $page
            ->where('projection.0.year', 2026)
            ->where('projection.0.income', 12000)
            ->where('projection.0.expenses', 0)
            ->where('projection.0.assets', 11000)
            ->where('projection.0.growth', 1000)
            ->where('projection.0.net_worth', 11000));
});

// Managing armadas

it('launches an armada and opens it', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('finance.armadas.store'), ['name' => 'Foundational', 'description' => 'The household.']);

    $armada = Armada::query()->sole();
    $response->assertRedirect(route('finance.armadas.show', $armada));
    expect($armada->only(['user_id', 'name', 'description']))->toBe(['user_id' => $user->id, 'name' => 'Foundational', 'description' => 'The household.']);
});

it('refuses an armada with no name', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('finance.armadas.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(Armada::query()->count())->toBe(0);
});

it('renames an armada', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id, 'name' => 'Old']);

    $this->actingAs($user)->patch(route('finance.armadas.update', $armada), ['name' => 'Business', 'description' => null])->assertRedirect();

    expect($armada->fresh()->name)->toBe('Business');
});

it('leaves what was in a disbanded armada in the fleet, unassigned', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    $holding = Holding::factory()->create(['user_id' => $user->id, 'armada_id' => $armada->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id, 'armada_id' => $armada->id]);

    $this->actingAs($user)->delete(route('finance.armadas.destroy', $armada))->assertRedirect(route('finance.armadas'));

    $this->assertModelMissing($armada);
    expect($holding->fresh()->armada_id)->toBeNull()
        ->and($flow->fresh()->armada_id)->toBeNull();
});

it('will not show, change or disband another user\'s armada', function (string $method, string $route) {
    $armada = Armada::factory()->create(['name' => 'Theirs']);

    $this->actingAs(User::factory()->create())->{$method}(route($route, $armada), ['name' => 'Mine'])->assertNotFound();

    expect($armada->fresh()->name)->toBe('Theirs');
})->with([
    ['get', 'finance.armadas.show'],
    ['patch', 'finance.armadas.update'],
    ['delete', 'finance.armadas.destroy'],
]);

// Assigning

it('moves holdings and standalone flows into an armada, and out again', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    $holding = Holding::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.armadas.assign'), ['armada_id' => $armada->id, 'holdings' => [$holding->id], 'flows' => [$flow->id]])->assertRedirect();

    expect($holding->fresh()->armada_id)->toBe($armada->id)
        ->and($flow->fresh()->armada_id)->toBe($armada->id);

    $this->actingAs($user)->put(route('finance.armadas.assign'), ['armada_id' => null, 'holdings' => [$holding->id]])->assertRedirect();

    expect($holding->fresh()->armada_id)->toBeNull();
});

it('will not move another user\'s holdings or flows, or use another user\'s armada', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    $theirHolding = Holding::factory()->create();
    $theirFlow = Flow::factory()->create();
    $theirArmada = Armada::factory()->create();
    $ownHolding = Holding::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.armadas.assign'), ['armada_id' => $armada->id, 'holdings' => [$theirHolding->id], 'flows' => [$theirFlow->id]])->assertRedirect();
    $this->actingAs($user)->put(route('finance.armadas.assign'), ['armada_id' => $theirArmada->id, 'holdings' => [$ownHolding->id]])->assertSessionHasErrors('armada_id');

    expect($theirHolding->fresh()->armada_id)->toBeNull()
        ->and($theirFlow->fresh()->armada_id)->toBeNull()
        ->and($ownHolding->fresh()->armada_id)->toBeNull();
});

it('saves the armada chosen on a holding or a flow, and only one of the user\'s own', function () {
    $user = User::factory()->create();
    $armada = Armada::factory()->create(['user_id' => $user->id]);
    $holding = ['type' => 'savings', 'name' => 'Rainy day', 'balance' => 100, 'annual_rate' => 4, 'monthly_contribution' => 0];
    $flow = ['direction' => 'expense', 'category' => 'subscriptions', 'name' => 'Netflix', 'amount' => 20, 'frequency' => 'monthly', 'annual_growth_rate' => 0, 'taxed_portion' => 100, 'is_essential' => false];

    $this->actingAs($user)->post(route('finance.fleet.store'), [...$holding, 'armada_id' => $armada->id])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('finance.flows.store'), [...$flow, 'armada_id' => $armada->id])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('finance.fleet.store'), [...$holding, 'armada_id' => Armada::factory()->create()->id])->assertSessionHasErrors('armada_id');

    expect(Holding::query()->sole()->armada_id)->toBe($armada->id)
        ->and(Flow::query()->sole()->armada_id)->toBe($armada->id);
});
