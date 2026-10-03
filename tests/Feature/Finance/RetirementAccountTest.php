<?php

use App\Models\Finance\Holding;
use App\Models\User;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\Fleet;
use App\Services\Finance\FleetProjector;

/**
 * @return array<string, mixed>
 */
function retirementPayload(array $overrides = []): array
{
    return [
        'type' => 'retirement',
        'plan_type' => '401k',
        'tax_type' => 'traditional',
        'name' => 'Work 401(k)',
        'institution' => null,
        'balance' => 50000,
        'annual_rate' => 7,
        'monthly_contribution' => 500,
        'secured_by_id' => null,
        'parent_id' => null,
        'notes' => null,
        ...$overrides,
    ];
}

// The two facts about a retirement account

it('records a retirement account\'s plan and tax type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.fleet.store'), retirementPayload(['plan_type' => 'sep_simple_ira', 'tax_type' => 'roth']))
        ->assertSessionHasNoErrors();

    expect(Holding::query()->onlyOwnedBy($user)->sole())
        ->type->toBe('retirement')
        ->plan_type->toBe('sep_simple_ira')
        ->tax_type->toBe('roth')
        ->type_label->toBe('Roth SEP / SIMPLE IRA');
});

it('will not take a retirement account without both facts', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('finance.fleet.store'), retirementPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'no plan' => [['plan_type' => null], 'plan_type'],
    'an unknown plan' => [['plan_type' => 'pension'], 'plan_type'],
    'no tax type' => [['tax_type' => null], 'tax_type'],
    'an unknown tax type' => [['tax_type' => 'deferred'], 'tax_type'],
]);

/**
 * Otherwise a holding edited from a Roth IRA into a savings account would go
 * on being sorted as tax-free money by the Retirement Strategizer.
 */
it('drops the retirement facts when the type is anything else', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->retirement('roth', 'ira')->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch(route('finance.fleet.update', $holding), retirementPayload(['type' => 'savings', 'plan_type' => 'ira', 'tax_type' => 'roth']))
        ->assertSessionHasNoErrors();

    expect($holding->fresh())
        ->plan_type->toBeNull()
        ->tax_type->toBeNull()
        ->tax_treatment->toBe('taxable');
});

it('taxes a retirement account by its tax type', function (string $taxType, string $treatment) {
    expect(Holding::factory()->retirement($taxType)->create()->tax_treatment)->toBe($treatment);
})->with([
    ['traditional', 'deferred'],
    ['roth', 'free'],
]);

// Compound accounts

/**
 * A Roth IRA holding two brokerage accounts.
 *
 * @return array{0: Holding, 1: Holding, 2: Holding}
 */
function compoundRoth(User $user): array
{
    // The parent's own figures are deliberately non-zero: they must be ignored.
    $parent = Holding::factory()->retirement('roth', 'ira')->create(['user_id' => $user->id, 'name' => 'Roth IRA', 'balance' => 999, 'annual_rate' => 1, 'monthly_contribution' => 77]);
    $first = Holding::factory()->inside($parent)->create(['name' => 'Index funds', 'balance' => 30000, 'annual_rate' => 6, 'monthly_contribution' => 300]);
    $second = Holding::factory()->inside($parent)->create(['name' => 'Stock picks', 'balance' => 10000, 'annual_rate' => 10, 'monthly_contribution' => 100]);

    return [$parent, $first, $second];
}

it('values a compound account at what is inside it, ignoring its own figures', function () {
    [$parent] = compoundRoth(User::factory()->create());

    // (30,000 x 6% + 10,000 x 10%) / 40,000
    expect($parent->value)->toBe(40000.0)
        ->and($parent->expected_rate)->toBe(7.0)
        ->and($parent->contribution)->toBe(400.0)
        ->and($parent->is_compound)->toBeTrue();
});

it('taxes an account as the account it sits inside', function () {
    [, $child] = compoundRoth(User::factory()->create());

    // A brokerage account is taxable on its own, and Roth money inside a Roth.
    expect($child->tax_treatment)->toBe('free')
        ->and($child->full_name)->toBe('Roth IRA › Index funds');
});

/**
 * The reason the listing and the projection are two different reads: listing
 * the children as well would count their money twice.
 */
it('counts the money inside a compound account once', function () {
    $user = User::factory()->create();
    compoundRoth($user);

    $fleet = new Fleet;
    $holdings = $fleet->holdings($user);

    expect($holdings)->toHaveCount(1)
        ->and($fleet->totals($holdings)['assets'])->toBe(40000.0)
        ->and($fleet->leaves($holdings)->pluck('name')->all())->toBe(['Index funds', 'Stock picks']);

    $this->actingAs($user)->get(route('finance.fleet'))
        ->assertInertia(fn ($page) => $page
            ->has('assets', 1)
            ->where('assets.0.children_count', 2)
            ->where('assets.0.value', 40000)
            ->where('totals.net_worth', 40000));
});

it('projects each account inside a compound one at its own rate', function () {
    $user = User::factory()->create();
    [, $first, $second] = compoundRoth($user);

    $fleet = new Fleet;
    $projection = (new FleetProjector)->project($fleet->leaves($fleet->holdings($user)), 12);

    // 30,000 at 6% plus 300 a month, and 10,000 at 10% plus 100 a month —
    // not 40,000 at the parent's own 1%.
    expect($projection['holdings'])->toHaveKeys([$first->id, $second->id])
        ->and($projection['assets'][0])->toBe(40000.0)
        ->and($projection['assets'][12])->toBeGreaterThan(40000 * 1.06 + 4800);
});

it('sorts the accounts inside a retirement account under its tax type for the strategizer', function () {
    $user = User::factory()->create();
    compoundRoth($user);
    $traditional = Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 5]);
    Holding::factory()->inside($traditional)->create(['balance' => 250000]);

    $balances = app(ConversionBoard::class)->for($user)['balances'];

    expect($balances['free'])->toEqual(40000)
        ->and($balances['deferred'])->toEqual(250000)
        ->and($balances['taxable'])->toEqual(0);
});

it('shows a compound account with the accounts inside it', function () {
    $user = User::factory()->create();
    [$parent, $first] = compoundRoth($user);

    $this->actingAs($user)->get(route('finance.fleet.show', $parent))
        ->assertInertia(fn ($page) => $page
            ->where('holding.value', 40000)
            ->where('holding.type_label', 'Roth IRA')
            ->has('children', 2)
            ->where('children.0.name', 'Index funds')
            ->where('parent', null));

    $this->actingAs($user)->get(route('finance.fleet.show', $first))
        ->assertInertia(fn ($page) => $page->where('parent.name', 'Roth IRA')->has('children', 0));
});

it('adds an account inside a retirement account, and returns to it', function () {
    $user = User::factory()->create();
    $parent = Holding::factory()->retirement()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('finance.fleet.store'), retirementPayload(['type' => 'brokerage', 'name' => 'Custodian A', 'parent_id' => $parent->id]))
        ->assertRedirect(route('finance.fleet.show', $parent));

    expect($parent->children()->sole())->name->toBe('Custodian A')->plan_type->toBeNull();
});

it('refuses an account placed somewhere it cannot go', function (Closure $parent, string $type) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('finance.fleet.store'), retirementPayload(['type' => $type, 'parent_id' => $parent($user)->id]))
        ->assertSessionHasErrors('parent_id');

    expect(Holding::query()->onlyOwnedBy($user)->where('name', 'Work 401(k)')->count())->toBe(0);
})->with([
    'inside another user\'s account' => [fn (User $user) => Holding::factory()->retirement()->create(), 'brokerage'],
    'inside a type that holds nothing' => [fn (User $user) => Holding::factory()->ofType('savings')->create(['user_id' => $user->id]), 'brokerage'],
    'a type the parent cannot hold' => [fn (User $user) => Holding::factory()->retirement()->create(['user_id' => $user->id]), 'real_estate'],
    'a retirement account inside a retirement account' => [fn (User $user) => Holding::factory()->retirement()->create(['user_id' => $user->id]), 'retirement'],
    'two levels down' => [
        fn (User $user) => Holding::factory()->inside(Holding::factory()->retirement()->create(['user_id' => $user->id]))->create(),
        'brokerage',
    ],
]);

it('will not change the type of an account that still holds others', function () {
    $user = User::factory()->create();
    [$parent] = compoundRoth($user);

    $this->actingAs($user)
        ->patch(route('finance.fleet.update', $parent), retirementPayload(['type' => 'brokerage']))
        ->assertSessionHasErrors('type');

    expect($parent->fresh()->type)->toBe('retirement');
});

it('removes the accounts inside a compound account along with it', function () {
    $user = User::factory()->create();
    [$parent] = compoundRoth($user);

    $this->actingAs($user)->delete(route('finance.fleet.destroy', $parent))->assertRedirect(route('finance.fleet'));

    expect(Holding::query()->count())->toBe(0);
});

it('returns to the outer account after removing one inside it', function () {
    $user = User::factory()->create();
    [$parent, $first] = compoundRoth($user);

    $this->actingAs($user)->delete(route('finance.fleet.destroy', $first))->assertRedirect(route('finance.fleet.show', $parent));

    expect($parent->fresh()->value)->toBe(10000.0);
});
