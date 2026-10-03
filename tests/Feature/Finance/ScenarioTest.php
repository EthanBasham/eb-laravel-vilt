<?php

use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\User;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * Someone born in 1976 who retires at 65 (2041) and plans to 90 (2066), so
 * the plan is the 41 years from 2026.
 */
function scenarioUser(): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1976-03-01', 'retirement_age' => 65, 'life_expectancy' => 90]);

    return $user;
}

// Projecting

it('projects a flow at its own rate, to the end of the plan, when the scenario says nothing about it', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly', 'annual_growth_rate' => 10]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Scenario')
            ->where('horizon.from', 2026)
            ->where('horizon.to', 2066)
            ->has('expenses.0.series', 41)
            ->where('expenses.0.rate', 10)
            ->where('expenses.0.has_scenario_rate', false)
            ->where('expenses.0.series.0', ['year' => 2026, 'age' => 50, 'base' => 12000, 'amount' => 12000, 'is_pinned' => false])
            ->where('expenses.0.series.2.amount', 14520)
            ->where('expenses.0.series.40.year', 2066));
});

it('grows a flow at the scenario rate in place of its own', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly', 'annual_growth_rate' => 10]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $flow->id, 'annual_growth_rate' => -50]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('expenses.0.own_rate', 10)
            ->where('expenses.0.rate', -50)
            ->where('expenses.0.has_scenario_rate', true)
            ->where('expenses.0.series.0.amount', 12000)
            ->where('expenses.0.series.1.amount', 6000)
            ->where('expenses.0.series.2.amount', 3000));
});

it('gives a pinned year its own amount and leaves the years around it to the rate', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $flow->id, 'overrides' => [2027 => 20000]]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('expenses.0.pinned_count', 1)
            ->where('expenses.0.series.1', ['year' => 2027, 'age' => 51, 'base' => 12000, 'amount' => 20000, 'is_pinned' => true])
            ->where('expenses.0.series.2.amount', 12000)
            ->where('expenses.0.total', 12000 * 40 + 20000));
});

/**
 * The stop at retirement comes from the flow, and holds under a scenario. A
 * pin is how part-time work after retiring is put back.
 */
it('stops earned income at retirement unless a later year is pinned', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $salary = Flow::factory()->income('salary')->create(['user_id' => $user->id, 'amount' => 5000, 'frequency' => 'monthly']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $salary->id, 'overrides' => [2042 => 18000]]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('horizon.retirement_year', 2041)
            ->where('income.0.series.14.amount', 60000)
            ->where('income.0.series.15.amount', 0)
            ->where('income.0.series.16.amount', 18000));
});

it('totals income against expenses for each year and over the whole plan', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 3000, 'frequency' => 'monthly', 'taxation' => null]);
    $rent = Flow::factory()->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $rent->id, 'overrides' => [2026 => 30000]]);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('totals.0', ['year' => 2026, 'age' => 50, 'income' => 36000, 'taxes' => 0, 'take_home' => 36000, 'expenses' => 30000, 'net' => 6000])
            ->where('totals.1.net', 24000)
            ->where('summary.income', 36000 * 41)
            ->where('summary.net', 24000 * 40 + 6000)
            ->where('net_vs_baseline', -18000));
});

/**
 * $36,000 of pension, single: $19,900 over the deduction, so 10% of $12,400
 * and 12% of the rest. The pension does not grow and the tables do, with
 * inflation, so the same dollars are taxed a little less each year after.
 */
it('takes each year\'s tax off what is left, on tables that move with inflation', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 3000, 'frequency' => 'monthly']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly']);

    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page
            ->where('income.0.taxation_label', 'Income tax only')
            ->where('totals.0', ['year' => 2026, 'age' => 50, 'income' => 36000, 'taxes' => 2140, 'take_home' => 33860, 'expenses' => 12000, 'net' => 21860])
            ->where('totals.1.taxes', fn (float|int $taxes) => $taxes > 0 && $taxes < 2140)
            ->where('summary.taxes', fn (float|int $taxes) => $taxes > 2140));
});

/**
 * The same flat pension as above. With the tables held still its tax is the
 * same $2,140 every year; left to the profile's 2.5% it falls; and a scenario
 * that raises the tables faster than that taxes it less again.
 */
it('raises the tax tables at the scenario\'s own rate when it has one', function () {
    $user = scenarioUser();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 3000, 'frequency' => 'monthly']);
    $profileRate = Scenario::factory()->create(['user_id' => $user->id]);
    $heldStill = Scenario::factory()->create(['user_id' => $user->id, 'bracket_inflation_rate' => 0]);
    $faster = Scenario::factory()->create(['user_id' => $user->id, 'bracket_inflation_rate' => 5]);

    $taxIn2036 = fn (Scenario $scenario): float => (float) $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))->inertiaProps('totals.10.taxes');

    $this->actingAs($user)->get(route('finance.scenarios.show', $heldStill))
        ->assertInertia(fn ($page) => $page
            ->where('bracket_inflation', ['rate' => 0, 'is_own' => true, 'profile_rate' => 2.5])
            ->where('totals.10.taxes', 2140)
            ->where('totals.40.taxes', 2140));

    $this->actingAs($user)->get(route('finance.scenarios.show', $profileRate))
        ->assertInertia(fn ($page) => $page->where('bracket_inflation', ['rate' => 2.5, 'is_own' => false, 'profile_rate' => 2.5]));

    expect($taxIn2036($profileRate))->toBeLessThan(2140)
        ->and($taxIn2036($faster))->toBeLessThan($taxIn2036($profileRate));
});

it('saves a scenario\'s rate for the tax tables, and keeps it through a rename that does not mention it', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('finance.scenarios.update', $scenario), ['name' => 'Optimistic', 'description' => null, 'bracket_inflation_rate' => 3.2])->assertSessionHasNoErrors();
    $this->actingAs($user)->patch(route('finance.scenarios.update', $scenario), ['name' => 'Renamed', 'description' => null])->assertSessionHasNoErrors();
    expect($scenario->fresh()->only(['name', 'bracket_inflation_rate']))->toBe(['name' => 'Renamed', 'bracket_inflation_rate' => 3.2]);

    $this->actingAs($user)->patch(route('finance.scenarios.update', $scenario), ['name' => 'Renamed', 'description' => null, 'bracket_inflation_rate' => null]);
    expect($scenario->fresh()->bracket_inflation_rate)->toBeNull();

    $this->actingAs($user)->patch(route('finance.scenarios.update', $scenario), ['name' => 'Renamed', 'bracket_inflation_rate' => 40])->assertSessionHasErrors('bracket_inflation_rate');
});

it('taxes a pinned year on the pinned amount', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $salary = Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $salary->id, 'overrides' => [2026 => 100000]]);

    // $100,000 of W-2 wages: $13,170 of income tax and $7,650 of FICA.
    $this->actingAs($user)->get(route('finance.scenarios.show', $scenario))
        ->assertInertia(fn ($page) => $page->where('totals.0.taxes', 20820)->where('totals.0.take_home', 79180));
});

it('lists each scenario with its lifetime totals beside the baseline', function () {
    $user = scenarioUser();
    $flow = Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly', 'taxation' => null]);
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Optimistic']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $flow->id, 'overrides' => [2030 => 22000]]);
    Scenario::factory()->create(['name' => 'Somebody else\'s']);

    $this->actingAs($user)->get(route('finance.scenarios'))
        ->assertInertia(fn ($page) => $page
            ->component('Scenarios')
            ->where('has_flows', true)
            ->where('baseline.summary.net', 12000 * 41)
            ->has('scenarios', 1)
            ->where('scenarios.0.name', 'Optimistic')
            ->where('scenarios.0.adjusted_count', 1)
            ->where('scenarios.0.net_vs_baseline', 10000)
            ->where('scenarios.0.left_over', ['total' => 502000, 'min' => ['amount' => 12000, 'year' => 2026], 'max' => ['amount' => 22000, 'year' => 2030]])
            ->has('scenarios.0.totals', 41));
});

it('reports a year that runs short as a negative left over', function () {
    $user = scenarioUser();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 1000, 'frequency' => 'monthly']);
    $rent = Flow::factory()->create(['user_id' => $user->id, 'amount' => 500, 'frequency' => 'monthly']);
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $rent->id, 'overrides' => [2040 => 20000]]);

    $this->actingAs($user)->get(route('finance.scenarios'))
        ->assertInertia(fn ($page) => $page
            ->where('scenarios.0.left_over.min', ['amount' => -8000, 'year' => 2040])
            ->where('scenarios.0.left_over.max', ['amount' => 6000, 'year' => 2026]));
});

it('has no left over to report for a scenario with nothing to project', function () {
    $user = scenarioUser();
    Scenario::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('finance.scenarios'))
        ->assertInertia(fn ($page) => $page->where('has_flows', false)->where('scenarios.0.left_over', null));
});

// Managing scenarios

it('adds a scenario and opens it', function () {
    $user = scenarioUser();

    $response = $this->actingAs($user)->post(route('finance.scenarios.store'), ['name' => 'Optimistic', 'description' => 'Raises every year']);

    $scenario = Scenario::query()->onlyOwnedBy($user)->sole();

    $response->assertRedirect(route('finance.scenarios.show', $scenario));
    expect($scenario->only(['name', 'description']))->toBe(['name' => 'Optimistic', 'description' => 'Raises every year']);
});

it('refuses a scenario with no name', function () {
    $this->actingAs(scenarioUser())->post(route('finance.scenarios.store'), ['name' => ''])->assertSessionHasErrors('name');

    expect(Scenario::query()->count())->toBe(0);
});

it('renames and removes a scenario', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('finance.scenarios.update', $scenario), ['name' => 'Cautious', 'description' => null])->assertSessionHasNoErrors();
    expect($scenario->fresh()->name)->toBe('Cautious');

    $this->actingAs($user)->delete(route('finance.scenarios.destroy', $scenario))->assertRedirect(route('finance.scenarios'));
    expect($scenario->fresh())->toBeNull();
});

it('copies a scenario with its rates and pinned years', function () {
    $user = scenarioUser();
    $flow = Flow::factory()->create(['user_id' => $user->id]);
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Optimistic']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $flow->id, 'annual_growth_rate' => 4, 'overrides' => [2030 => 500]]);

    $this->actingAs($user)->post(route('finance.scenarios.duplicate', $scenario));

    $copy = Scenario::query()->where('name', 'Optimistic copy')->sole();

    expect($copy->user_id)->toBe($user->id)
        ->and($copy->scenarioFlows->sole()->only(['flow_id', 'annual_growth_rate', 'overrides']))
        ->toBe(['flow_id' => $flow->id, 'annual_growth_rate' => 4.0, 'overrides' => [2030 => 500]]);
});

it('hides another user\'s scenario behind a 404', function (string $method, string $route, array $payload) {
    $scenario = Scenario::factory()->create();

    $this->actingAs(scenarioUser())->{$method}(route($route, $scenario), $payload)->assertNotFound();

    expect($scenario->fresh())->not->toBeNull()
        ->and(Scenario::query()->count())->toBe(1);
})->with([
    'show' => ['get', 'finance.scenarios.show', []],
    'update' => ['patch', 'finance.scenarios.update', ['name' => 'Mine now']],
    'duplicate' => ['post', 'finance.scenarios.duplicate', []],
    'destroy' => ['delete', 'finance.scenarios.destroy', []],
    'rates' => ['put', 'finance.scenarios.rates.update', ['direction' => 'income', 'annual_growth_rate' => 3]],
]);

// Adjusting a flow

it('saves a rate and pinned years for one flow', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.scenarios.flows.update', [$scenario, $flow]), ['annual_growth_rate' => 3.5, 'overrides' => [2031 => 900.5, 2028 => 400]])
        ->assertSessionHasNoErrors();

    expect($scenario->scenarioFlows()->sole()->only(['flow_id', 'annual_growth_rate', 'overrides']))
        ->toBe(['flow_id' => $flow->id, 'annual_growth_rate' => 3.5, 'overrides' => [2028 => 400, 2031 => 900.5]]);
});

it('replaces what was saved for the flow rather than adding to it', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $flow->id, 'annual_growth_rate' => 4, 'overrides' => [2030 => 500]]);

    $this->actingAs($user)->put(route('finance.scenarios.flows.update', [$scenario, $flow]), ['annual_growth_rate' => null, 'overrides' => [2032 => 700]]);

    expect($scenario->scenarioFlows()->sole()->only(['annual_growth_rate', 'overrides']))->toBe(['annual_growth_rate' => null, 'overrides' => [2032 => 700]]);
});

it('forgets a flow handed back its own rate with no pinned years', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $flow->id, 'annual_growth_rate' => 4]);

    $this->actingAs($user)->put(route('finance.scenarios.flows.update', [$scenario, $flow]), ['annual_growth_rate' => null, 'overrides' => []])
        ->assertSessionHasNoErrors();

    expect(ScenarioFlow::query()->count())->toBe(0);
});

it('refuses settings that could not be projected', function (array $payload, string $field) {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $flow = Flow::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.scenarios.flows.update', [$scenario, $flow]), $payload)->assertSessionHasErrors($field);

    expect(ScenarioFlow::query()->count())->toBe(0);
})->with([
    'a rate off the scale' => [['annual_growth_rate' => 80, 'overrides' => []], 'annual_growth_rate'],
    'a negative amount' => [['annual_growth_rate' => null, 'overrides' => [2030 => -1]], 'overrides.2030'],
    'a year already gone' => [['annual_growth_rate' => null, 'overrides' => [2025 => 100]], 'overrides'],
    'a key that is not a year' => [['annual_growth_rate' => null, 'overrides' => ['soon' => 100]], 'overrides'],
]);

it('will not adjust a flow or a scenario that belongs to someone else', function () {
    $user = scenarioUser();
    $mine = Scenario::factory()->create(['user_id' => $user->id]);
    $theirs = Scenario::factory()->create();
    $payload = ['annual_growth_rate' => 5, 'overrides' => []];

    $this->actingAs($user)->put(route('finance.scenarios.flows.update', [$mine, Flow::factory()->create()]), $payload)->assertNotFound();
    $this->actingAs($user)->put(route('finance.scenarios.flows.update', [$theirs, Flow::factory()->create(['user_id' => $user->id])]), $payload)->assertNotFound();

    expect(ScenarioFlow::query()->count())->toBe(0);
});

it('sets one rate across every income, leaving expenses and pinned years alone', function () {
    $user = scenarioUser();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $salary = Flow::factory()->income()->create(['user_id' => $user->id]);
    $pension = Flow::factory()->income('pension')->create(['user_id' => $user->id]);
    Flow::factory()->create(['user_id' => $user->id]);
    Flow::factory()->income()->create();
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $salary->id, 'annual_growth_rate' => 1, 'overrides' => [2030 => 500]]);

    $this->actingAs($user)->put(route('finance.scenarios.rates.update', $scenario), ['direction' => 'income', 'annual_growth_rate' => 4])
        ->assertSessionHasNoErrors();

    expect($scenario->scenarioFlows()->orderBy('flow_id')->get()->map->only(['flow_id', 'annual_growth_rate', 'overrides'])->all())->toBe([
        ['flow_id' => $salary->id, 'annual_growth_rate' => 4.0, 'overrides' => [2030 => 500]],
        ['flow_id' => $pension->id, 'annual_growth_rate' => 4.0, 'overrides' => null],
    ]);
});

it('takes a scenario\'s settings with a flow when the flow is removed', function () {
    $user = scenarioUser();
    $flow = Flow::factory()->create(['user_id' => $user->id]);
    ScenarioFlow::factory()->create(['scenario_id' => Scenario::factory()->create(['user_id' => $user->id])->id, 'flow_id' => $flow->id, 'annual_growth_rate' => 4]);

    $this->actingAs($user)->delete(route('finance.flows.destroy', $flow));

    expect(ScenarioFlow::query()->count())->toBe(0)
        ->and(Scenario::query()->count())->toBe(1);
});
