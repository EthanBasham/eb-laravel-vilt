<?php

use App\Models\Finance\Armada;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioHolding;
use App\Models\Finance\Transfer;
use App\Models\User;
use App\Services\Finance\Fleet;
use App\Services\Finance\FleetLedger;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * Runs the ledger over a user's whole fleet, and hands back one holding's
 * years.
 *
 * @param  array<string, mixed>  $with  Named arguments for FleetLedger::run() beyond the holdings and years.
 * @return list<array<string, float|int|bool>>
 */
function ledgerYears(Holding $holding, array $years = [2026], array $with = []): array
{
    $fleet = app(Fleet::class);
    $leaves = $fleet->leaves($fleet->holdings($holding->user));

    return app(FleetLedger::class)->run($leaves, $years, ...$with)['holdings'][$holding->id];
}

function cashAccount(User $user, float $balance, array $more = []): Holding
{
    return Holding::factory()->ofType('cash')->create(['user_id' => $user->id, 'balance' => $balance, 'annual_rate' => 0, ...$more]);
}

// Growth apart from money moved

it('keeps what an asset earned apart from what was put into it', function () {
    $holding = Holding::factory()->create(['balance' => 10000, 'annual_rate' => 10, 'monthly_contribution' => 0]);
    $saver = Holding::factory()->create(['user_id' => $holding->user_id, 'balance' => 10000, 'annual_rate' => 0, 'monthly_contribution' => 100]);

    $grown = ledgerYears($holding)[0];
    $saved = ledgerYears($saver)[0];

    expect($grown['growth'])->toEqualWithDelta(1000, 0.01)
        ->and($grown['contributions'])->toBe(0.0)
        ->and($grown['end'])->toEqualWithDelta(11000, 0.01)
        ->and($saved['growth'])->toBe(0.0)
        ->and($saved['contributions'])->toBe(1200.0)
        ->and($saved['end'])->toBe(11200.0);
});

it('stops contributions to an asset from the year of retirement', function () {
    $holding = Holding::factory()->create(['balance' => 0, 'annual_rate' => 0, 'monthly_contribution' => 100]);

    $years = ledgerYears($holding, [2026, 2027], ['retirementYear' => 2027]);

    expect($years[0]['contributions'])->toBe(1200.0)
        ->and($years[1]['contributions'])->toBe(0.0)
        ->and($years[1]['end'])->toBe(1200.0);
});

it('pays a debt down by its payment until nothing is owed, and no further', function () {
    $loan = Holding::factory()->liability('personal_loan')->create(['balance' => 1500, 'annual_rate' => 0, 'monthly_contribution' => 100]);

    $years = ledgerYears($loan, [2026, 2027]);

    expect($years[0]['end'])->toBe(300.0)
        ->and($years[1]['contributions'])->toBe(300.0)
        ->and($years[1]['end'])->toBe(0.0);
});

it('charges a debt a twelfth of its rate each month', function () {
    $loan = Holding::factory()->liability('personal_loan')->create(['balance' => 1000, 'annual_rate' => 12, 'monthly_contribution' => 0]);

    // 1% a month, compounding: 1000 × 1.01^12.
    expect(ledgerYears($loan)[0]['end'])->toEqualWithDelta(1126.83, 0.01);
});

// Flows that name an account

it('pays a routed income in after the year\'s tax, and a routed expense out', function () {
    $user = User::factory()->create();
    $checking = cashAccount($user, 0);

    $year = ledgerYears($checking, [2026], [
        'routed' => [
            ['account_id' => $checking->id, 'direction' => 'income', 'taxable_share' => 1.0, 'amounts' => [2026 => 12000.0]],
            ['account_id' => $checking->id, 'direction' => 'expense', 'taxable_share' => 0.0, 'amounts' => [2026 => 6000.0]],
        ],
        'taxRates' => [2026 => 0.25],
    ])[0];

    expect($year['deposits'])->toEqualWithDelta(9000, 0.01)
        ->and($year['withdrawals'])->toEqualWithDelta(6000, 0.01)
        ->and($year['unfunded'])->toBe(0.0)
        ->and($year['end'])->toEqualWithDelta(3000, 0.01);
});

it('counts what an account could not pay as unfunded rather than going below nothing', function () {
    $user = User::factory()->create();
    $checking = cashAccount($user, 1000);

    $year = ledgerYears($checking, [2026], [
        'routed' => [['account_id' => $checking->id, 'direction' => 'expense', 'taxable_share' => 0.0, 'amounts' => [2026 => 2400.0]]],
    ])[0];

    expect($year['withdrawals'])->toEqualWithDelta(1000, 0.01)
        ->and($year['unfunded'])->toEqualWithDelta(1400, 0.01)
        ->and($year['end'])->toEqualWithDelta(0, 0.01);
});

// Transfers

it('sweeps what is above the balance to keep, up to the month\'s limit when there is one', function (?float $limit, float $firstYearOut) {
    $user = User::factory()->create();
    $checking = cashAccount($user, 5000);
    $savings = cashAccount($user, 0);
    $transfer = Transfer::factory()->between($checking, $savings)->ofKind('sweep', ['keep_balance' => 1000, 'amount' => $limit])->create();

    $from = ledgerYears($checking, [2026, 2027], ['transfers' => collect([$transfer])]);
    $to = ledgerYears($savings, [2026, 2027], ['transfers' => collect([$transfer])]);

    expect($from[0]['transfers_out'])->toBe($firstYearOut)
        ->and($from[1]['end'])->toBe(1000.0)
        ->and($to[1]['end'])->toBe(4000.0);
})->with([
    'no limit' => [null, 4000.0],
    'at most $200 a month' => [200.0, 2400.0],
]);

it('sends a fixed amount to a debt until it is paid, and no more than is owed', function () {
    $user = User::factory()->create();
    $checking = cashAccount($user, 5000);
    $card = Holding::factory()->liability('credit_card')->create(['user_id' => $user->id, 'balance' => 1000, 'annual_rate' => 0, 'monthly_contribution' => 0]);
    $transfer = Transfer::factory()->between($checking, $card)->ofKind('fixed', ['amount' => 300])->create();

    $from = ledgerYears($checking, [2026], ['transfers' => collect([$transfer])])[0];
    $to = ledgerYears($card, [2026], ['transfers' => collect([$transfer])])[0];

    expect($from['transfers_out'])->toBe(1000.0)
        ->and($from['end'])->toBe(4000.0)
        ->and($to['transfers_in'])->toBe(1000.0)
        ->and($to['end'])->toBe(0.0);
});

it('refills the destination of a top-up to the balance it keeps, as far as the source has it', function (float $source, float $moved) {
    $user = User::factory()->create();
    $checking = cashAccount($user, $source);
    $cushion = cashAccount($user, 500);
    $transfer = Transfer::factory()->between($checking, $cushion)->ofKind('top_up', ['keep_balance' => 2000])->create();

    $to = ledgerYears($cushion, [2026], ['transfers' => collect([$transfer])])[0];

    expect($to['transfers_in'])->toBe($moved)
        ->and($to['end'])->toBe(500.0 + $moved);
})->with([
    'enough to refill it' => [5000.0, 1500.0],
    'not enough' => [400.0, 400.0],
]);

it('runs the transfers in their order', function () {
    $user = User::factory()->create();
    $checking = cashAccount($user, 1000);
    $first = cashAccount($user, 0);
    $second = cashAccount($user, 0);
    $transfers = collect([
        Transfer::factory()->between($checking, $first)->ofKind('sweep')->create(['sort_order' => 1]),
        Transfer::factory()->between($checking, $second)->ofKind('sweep')->create(['sort_order' => 2]),
    ]);

    expect(ledgerYears($first, [2026], ['transfers' => $transfers])[0]['end'])->toBe(1000.0)
        ->and(ledgerYears($second, [2026], ['transfers' => $transfers])[0]['end'])->toBe(0.0);
});

// On a scenario's page

function ledgerUser(): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1976-03-01', 'retirement_age' => 65, 'life_expectancy' => 90]);

    return $user;
}

it('projects each holding beside the flows, with the fleet\'s net worth year by year', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    Holding::factory()->create(['user_id' => $user->id, 'name' => 'Brokerage', 'balance' => 10000, 'annual_rate' => 10]);
    Holding::factory()->liability('personal_loan')->create(['user_id' => $user->id, 'balance' => 6000, 'annual_rate' => 0, 'monthly_contribution' => 100]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->has('assets', 1)
            ->where('assets.0.name', 'Brokerage')
            ->where('assets.0.start', 10000)
            ->where('assets.0.series.0.amount', 11000)
            ->where('assets.0.series.0.growth', 1000)
            ->has('liabilities', 1)
            ->where('liabilities.0.series.0.amount', 4800)
            ->where('net_worth.0', ['year' => 2026, 'age' => 50, 'assets' => 11000, 'liabilities' => 4800, 'net_worth' => 6200])
            ->where('unfunded', 0));
});

it('routes an income into the account it names, net of the year\'s tax', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $checking = cashAccount($user, 0);
    // Untaxed, so the whole of it lands.
    Flow::factory()->income('other_income')->create(['user_id' => $user->id, 'taxation' => null, 'amount' => 1000, 'account_id' => $checking->id]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 400, 'account_id' => $checking->id]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('assets.0.series.0.deposits', 12000)
            ->where('assets.0.series.0.withdrawals', 4800)
            ->where('assets.0.series.0.amount', 7200)
            ->where('assets.0.moved', fn ($moved) => $moved > 7200));
});

it('gives a holding the scenario\'s rate and contribution, and carries on from a pinned year', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $holding = Holding::factory()->create(['user_id' => $user->id, 'balance' => 10000, 'annual_rate' => 3, 'monthly_contribution' => 50]);
    ScenarioHolding::query()->create(['scenario_id' => $scenario->id, 'holding_id' => $holding->id, 'annual_rate' => 10, 'monthly_contribution' => 0, 'overrides' => [2026 => 20000]]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('assets.0.own_rate', 3)
            ->where('assets.0.rate', 10)
            ->where('assets.0.has_scenario_rate', true)
            ->where('assets.0.contribution', 0)
            ->where('assets.0.has_scenario_contribution', true)
            ->where('assets.0.pinned_count', 1)
            ->where('assets.0.series.0', fn ($year) => $year['base'] == 11000 && $year['amount'] == 20000 && $year['is_pinned'] === true)
            // The year after grows from the pinned value, not from the rate's.
            ->where('assets.0.series.1.base', 12100)
            ->where('assets.0.series.1.amount', 22000));
});

it('adds the plan up armada by armada', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $armada = Armada::factory()->create(['user_id' => $user->id, 'name' => 'Real estate']);
    Holding::factory()->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'balance' => 10000, 'annual_rate' => 0]);
    Holding::factory()->create(['user_id' => $user->id, 'balance' => 500, 'annual_rate' => 0]);
    Flow::factory()->create(['user_id' => $user->id, 'armada_id' => $armada->id, 'amount' => 100, 'ends_on' => '2026-12-31']);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->has('armadas', 2)
            ->where('armadas.0.name', 'Real estate')
            ->where('armadas.0.expenses', 1200)
            ->where('armadas.0.net_worth_start', 10000)
            ->where('armadas.1.name', 'Unassigned')
            ->where('armadas.1.net_worth_end', 500));
});

// Saving a scenario's say over a holding

it('saves what a scenario changes about a holding, and forgets it when nothing is left', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $holding = Holding::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.scenarios.holdings.update', [$scenario, $holding]), ['annual_rate' => 4.5, 'monthly_contribution' => null, 'overrides' => [2030 => 50000]])->assertRedirect();

    $saved = ScenarioHolding::query()->sole();
    expect($saved->annual_rate)->toBe(4.5)
        ->and($saved->monthly_contribution)->toBeNull()
        ->and($saved->overrides)->toBe([2030 => 50000]);

    $this->actingAs($user)->put(route('finance.scenarios.holdings.update', [$scenario, $holding]), ['annual_rate' => null, 'monthly_contribution' => null, 'overrides' => []])->assertRedirect();

    expect(ScenarioHolding::query()->count())->toBe(0);
});

it('refuses a year-end pinned in the past', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $holding = Holding::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.scenarios.holdings.update', [$scenario, $holding]), ['annual_rate' => null, 'monthly_contribution' => null, 'overrides' => [2020 => 1]])
        ->assertSessionHasErrors(['overrides' => 'Only a year from this one on can be given a value of its own.']);

    expect(ScenarioHolding::query()->count())->toBe(0);
});

it('will not set another user\'s holding, or anything in another user\'s scenario', function (Closure $scenario, Closure $holding) {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('finance.scenarios.holdings.update', [$scenario($user), $holding($user)]), ['annual_rate' => 1, 'monthly_contribution' => null, 'overrides' => []])->assertNotFound();

    expect(ScenarioHolding::query()->count())->toBe(0);
})->with([
    'their holding' => [fn (User $user) => Scenario::factory()->create(['user_id' => $user->id]), fn () => Holding::factory()->create()],
    'their scenario' => [fn () => Scenario::factory()->create(), fn (User $user) => Holding::factory()->create(['user_id' => $user->id])],
]);

it('carries a scenario\'s holdings over to its copy', function () {
    $user = ledgerUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $holding = Holding::factory()->create(['user_id' => $user->id]);
    ScenarioHolding::query()->create(['scenario_id' => $scenario->id, 'holding_id' => $holding->id, 'annual_rate' => 9]);

    $this->actingAs($user)->post(route('finance.scenarios.duplicate', $scenario))->assertRedirect();

    $copy = Scenario::query()->latest('id')->first();
    expect($copy->scenarioHoldings()->sole()->only(['holding_id', 'annual_rate']))->toBe(['holding_id' => $holding->id, 'annual_rate' => 9.0]);
});
