<?php

use App\Models\Finance\Holding;
use App\Models\Finance\Transfer;
use App\Models\User;

/**
 * @return array<string, mixed>
 */
function transferPayload(Holding $from, Holding $to, array $overrides = []): array
{
    return [
        'name' => 'Sweep to savings',
        'kind' => 'sweep',
        'from_holding_id' => $from->id,
        'to_holding_id' => $to->id,
        'amount' => null,
        'keep_balance' => 2000,
        'sort_order' => 1,
        'is_active' => true,
        ...$overrides,
    ];
}

it('lists the transfers on the income & expenses page, in the order they run', function () {
    $user = User::factory()->create();
    $checking = Holding::factory()->create(['user_id' => $user->id, 'name' => 'Checking']);
    $savings = Holding::factory()->create(['user_id' => $user->id, 'name' => 'Savings']);
    Transfer::factory()->between($checking, $savings)->create(['name' => 'Second', 'sort_order' => 2]);
    Transfer::factory()->between($checking, $savings)->create(['name' => 'First', 'sort_order' => 1]);

    $this->actingAs($user)->get(route('finance.cashflow'))
        ->assertInertia(fn ($page) => $page
            ->has('transfers', 2)
            ->where('transfers.0.name', 'First')
            ->where('transfers.0.from_name', 'Checking')
            ->where('transfers.0.to_name', 'Savings')
            ->where('transfers.0.kind_label', 'Sweep what is left over'));
});

it('adds, changes and removes a transfer', function () {
    $user = User::factory()->create();
    $checking = Holding::factory()->create(['user_id' => $user->id]);
    $savings = Holding::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('finance.transfers.store'), transferPayload($checking, $savings))->assertSessionHasNoErrors();

    $transfer = Transfer::query()->sole();
    expect($transfer->only(['user_id', 'kind', 'from_holding_id', 'to_holding_id', 'keep_balance']))
        ->toBe(['user_id' => $user->id, 'kind' => 'sweep', 'from_holding_id' => $checking->id, 'to_holding_id' => $savings->id, 'keep_balance' => 2000.0]);

    $this->actingAs($user)->patch(route('finance.transfers.update', $transfer), transferPayload($checking, $savings, ['kind' => 'fixed', 'amount' => 250, 'is_active' => false]))->assertSessionHasNoErrors();

    expect($transfer->fresh()->only(['kind', 'amount', 'is_active']))->toBe(['kind' => 'fixed', 'amount' => 250.0, 'is_active' => false]);

    $this->actingAs($user)->delete(route('finance.transfers.destroy', $transfer))->assertRedirect();

    $this->assertModelMissing($transfer);
});

it('refuses a transfer it cannot carry out', function (Closure $payload, string $field, string $message) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.transfers.store'), $payload($user))->assertSessionHasErrors([$field => $message]);

    expect(Transfer::query()->count())->toBe(0);
})->with([
    'a fixed transfer with no amount' => [
        fn (User $user) => transferPayload(Holding::factory()->create(['user_id' => $user->id]), Holding::factory()->create(['user_id' => $user->id]), ['kind' => 'fixed']),
        'amount', 'Say how much to move each month.',
    ],
    'one end only' => [
        function (User $user) {
            $holding = Holding::factory()->create(['user_id' => $user->id]);

            return transferPayload($holding, $holding);
        },
        'to_holding_id', 'A transfer needs two different ends.',
    ],
    'out of a debt' => [
        fn (User $user) => transferPayload(Holding::factory()->liability()->create(['user_id' => $user->id]), Holding::factory()->create(['user_id' => $user->id])),
        'from_holding_id', 'The selected from holding id is invalid.',
    ],
    'out of another user\'s account' => [
        fn (User $user) => transferPayload(Holding::factory()->create(), Holding::factory()->create(['user_id' => $user->id])),
        'from_holding_id', 'The selected from holding id is invalid.',
    ],
    'into another user\'s account' => [
        fn (User $user) => transferPayload(Holding::factory()->create(['user_id' => $user->id]), Holding::factory()->create()),
        'to_holding_id', 'The selected to holding id is invalid.',
    ],
    'topping up a debt' => [
        fn (User $user) => transferPayload(Holding::factory()->create(['user_id' => $user->id]), Holding::factory()->liability()->create(['user_id' => $user->id]), ['kind' => 'top_up']),
        'to_holding_id', 'A top-up refills an asset. To pay a debt down, sweep or send a fixed amount to it.',
    ],
    'into an account that only holds others' => [
        function (User $user) {
            $ira = Holding::factory()->retirement('roth', 'ira')->create(['user_id' => $user->id]);
            Holding::factory()->inside($ira)->create();

            return transferPayload(Holding::factory()->create(['user_id' => $user->id]), $ira);
        },
        'to_holding_id', 'Pick one of the accounts inside it.',
    ],
]);

it('will not change or remove another user\'s transfer', function (string $method, string $route) {
    $user = User::factory()->create();
    $transfer = Transfer::factory()->create(['name' => 'Theirs']);
    $own = [Holding::factory()->create(['user_id' => $user->id]), Holding::factory()->create(['user_id' => $user->id])];

    $this->actingAs($user)->{$method}(route($route, $transfer), transferPayload(...$own))->assertNotFound();

    expect($transfer->fresh()->name)->toBe('Theirs');
})->with([
    ['patch', 'finance.transfers.update'],
    ['delete', 'finance.transfers.destroy'],
]);

it('pays a flow into one of the user\'s own assets only', function (Closure $account, bool $accepted) {
    $user = User::factory()->create();
    $payload = ['direction' => 'expense', 'category' => 'subscriptions', 'name' => 'Netflix', 'amount' => 20, 'frequency' => 'monthly', 'annual_growth_rate' => 0, 'taxed_portion' => 100, 'is_essential' => false, 'account_id' => $account($user)->id];

    $response = $this->actingAs($user)->post(route('finance.flows.store'), $payload);

    $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('account_id');
})->with([
    'their own asset' => [fn (User $user) => Holding::factory()->create(['user_id' => $user->id]), true],
    'their own debt' => [fn (User $user) => Holding::factory()->liability()->create(['user_id' => $user->id]), false],
    'another user\'s asset' => [fn () => Holding::factory()->create(), false],
]);
