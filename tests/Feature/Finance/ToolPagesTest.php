<?php

use App\Models\Finance\Flow;
use App\Models\Finance\Goal;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Snapshot;
use App\Models\User;
use App\Services\Finance\SampleFleet;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/*
 * Every page, twice: for someone who has entered nothing, and for the sample
 * household. The first is the one most likely to divide by zero.
 */
dataset('pages', [
    'overview' => ['finance.overview', 'Overview'],
    'fleet' => ['finance.fleet', 'Fleet'],
    'cashflow' => ['finance.cashflow', 'Cashflow'],
    'scenarios' => ['finance.scenarios', 'Scenarios'],
    'budget' => ['finance.budget', 'Budget'],
    'goals' => ['finance.goals', 'Goals'],
    'projector' => ['finance.projector', 'Projector'],
    'real estate' => ['finance.real-estate', 'RealEstate'],
    'retirement' => ['finance.retirement', 'Retirement'],
    'reality' => ['finance.reality', 'Reality'],
    'calculators' => ['finance.calculators', 'Calculators'],
    'settings' => ['finance.settings', 'Settings'],
]);

it('renders for someone with an empty fleet', function (string $route, string $component) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with('pages');

it('renders for the sample fleet', function (string $route, string $component) {
    $user = User::factory()->create();
    app(SampleFleet::class)->load($user);

    $this->actingAs($user)
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with('pages');

it('sends a guest to sign in', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('pages');

it('keeps the finance island off the World of Tanks root view', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('finance.overview'))
        ->assertViewIs('finance');
});

// Overview

it('summarises the fleet on the overview', function () {
    $user = User::factory()->create();
    Holding::factory()->ofType('savings')->create(['user_id' => $user->id, 'balance' => 30000, 'annual_rate' => 0]);
    Holding::factory()->liability('credit_card')->create(['user_id' => $user->id, 'name' => 'Card', 'balance' => 6000, 'annual_rate' => 24]);
    Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 6000, 'taxation' => null]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 5000]);

    $this->actingAs($user)->get(route('finance.overview'))
        ->assertInertia(fn ($page) => $page
            ->where('is_empty', false)
            ->where('totals.net_worth', 24000)
            ->where('cashflow.net', 1000)
            ->where('projection.0.net_worth', 24000)
            ->has('projection', 31)
            // 30,000 of cash against 5,000 a month.
            ->where('insights.0.title', '6.0 months of runway')
            // 6,000 at 24% is 120 a month.
            ->where('insights.2.title', '$120 a month in high-rate interest'));
});

// Projector

it('projects only the investable accounts, across three scenarios', function () {
    $user = User::factory()->create();
    Holding::factory()->create(['user_id' => $user->id, 'balance' => 10000, 'annual_rate' => 7]);
    Holding::factory()->ofType('real_estate')->create(['user_id' => $user->id, 'balance' => 500000]);

    $this->actingAs($user)->get(route('finance.projector', ['years' => 1, 'spread' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('holdings', 1)
            ->has('series', 2)
            ->where('summary.start', 10000)
            ->where('series.1.cautious', 10500)
            ->where('series.1.expected', 10700)
            ->where('series.1.optimistic', 10900));
});

it('takes inflation out when asked for today\'s dollars', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'inflation_rate' => 7]);
    Holding::factory()->create(['user_id' => $user->id, 'balance' => 10000, 'annual_rate' => 7]);

    $this->actingAs($user)->get(route('finance.projector', ['years' => 1, 'real' => 1]))
        ->assertInertia(fn ($page) => $page->where('series.1.expected', 10000));
});

// Calculators

it('opens each calculator, and 404s on one that does not exist', function () {
    $user = User::factory()->create();

    foreach (['mortgage', 'compound', 'payoff', 'savings'] as $tool) {
        $this->actingAs($user)->get(route('finance.calculators', $tool))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tool', $tool)->has('result.inputs'));
    }

    $this->actingAs($user)->get(route('finance.calculators', 'horoscope'))->assertNotFound();
});

it('feeds the query string into a calculator', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('finance.calculators', ['tool' => 'mortgage', 'price' => 375000, 'down_pct' => 20, 'rate' => 6]))
        ->assertInertia(fn ($page) => $page->where('result.payment', 1798.65));
});

// Snapshots

it('takes one snapshot a day, replacing it on a retake', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->create(['user_id' => $user->id, 'balance' => 10000, 'annual_rate' => 0]);

    $this->actingAs($user)->post(route('finance.snapshots.store'), ['note' => 'First'])->assertRedirect();

    $holding->update(['balance' => 15000]);

    $this->actingAs($user)->post(route('finance.snapshots.store'), ['note' => 'Again']);

    expect(Snapshot::query()->onlyOwnedBy($user)->sole())
        ->net_worth->toBe(15000.0)
        ->note->toBe('Again')
        ->projection->toHaveCount(121);
});

it('measures later snapshots against what the first one projected', function () {
    $user = User::factory()->create();
    $projection = array_map(fn (int $month): int => 100000 + $month * 1000, range(0, 120));

    Snapshot::query()->create(['user_id' => $user->id, 'taken_on' => '2026-04-01', 'assets' => 100000, 'liabilities' => 0, 'net_worth' => 100000, 'projection' => $projection]);
    Snapshot::query()->create(['user_id' => $user->id, 'taken_on' => '2026-10-01', 'assets' => 110000, 'liabilities' => 0, 'net_worth' => 110000, 'projection' => []]);

    // Six months on the baseline expected 106,000; reality is 4,000 ahead.
    $this->actingAs($user)->get(route('finance.reality'))
        ->assertInertia(fn ($page) => $page
            ->has('snapshots', 2)
            ->where('snapshots.0.expected', 106000)
            ->where('snapshots.0.variance', 4000)
            ->where('has_snapshot_today', true));
});

it('will not remove another user\'s snapshot', function () {
    $snapshot = Snapshot::query()->create(['user_id' => User::factory()->create()->id, 'taken_on' => '2026-04-01', 'assets' => 1, 'liabilities' => 0, 'net_worth' => 1, 'projection' => []]);

    $this->actingAs(User::factory()->create())->delete(route('finance.snapshots.destroy', $snapshot))->assertNotFound();

    expect($snapshot->fresh())->not->toBeNull();
});

it('takes tax off the monthly cash flow on the overview', function () {
    $user = User::factory()->create();
    Flow::factory()->income()->create(['user_id' => $user->id, 'amount' => 2000, 'frequency' => 'biweekly']);

    $this->actingAs($user)->get(route('finance.overview'))
        ->assertInertia(fn ($page) => $page->where('cashflow.taxes', 669.83)->where('cashflow.net', 3663.5));
});

// Settings and the sample fleet

it('saves the profile the tools read', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('finance.settings.update'), [
        'birth_date' => '1962-07-04', 'filing_status' => 'married_joint', 'retirement_age' => 63, 'life_expectancy' => 95, 'inflation_rate' => 3,
        'state' => null, 'state_deduction' => 0, 'state_brackets' => [], 'local_name' => null, 'local_deduction' => 0, 'local_brackets' => [],
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.settings'))
        ->assertInertia(fn ($page) => $page
            ->where('profile.age', 64)
            ->where('profile.rmd_start_age', 75)
            ->where('profile.filing_status', 'married_joint')
            ->where('tax.deduction', 32200));
});

it('shows the IRMAA tiers for the saved filing status', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'filing_status' => 'married_joint']);

    $this->actingAs($user)->get(route('finance.settings'))
        ->assertInertia(fn ($page) => $page
            ->where('irmaa.year', 2026)
            ->where('irmaa.income_year', 2024)
            ->has('irmaa.tiers', 6)
            ->where('irmaa.tiers.0', [218000, 202.9, 0])
            ->where('irmaa.tiers.5', [null, 689.9, 91]));
});

it('does not write a profile row just because a page was opened', function () {
    $this->actingAs(User::factory()->create())->get(route('finance.settings'))->assertOk();

    expect(Profile::query()->count())->toBe(0);
});

it('refuses a profile that plans to die before retiring', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('finance.settings.update'), ['birth_date' => null, 'filing_status' => 'single', 'retirement_age' => 70, 'life_expectancy' => 65, 'inflation_rate' => 2, 'state_deduction' => 0, 'state_brackets' => [], 'local_deduction' => 0, 'local_brackets' => []])
        ->assertSessionHasErrors('life_expectancy');
});

it('loads the sample fleet into an empty one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.sample.load'))->assertRedirect(route('finance.overview'));

    expect(Holding::query()->onlyOwnedBy($user)->count())->toBeGreaterThan(10)
        ->and(Flow::query()->onlyOwnedBy($user)->count())->toBeGreaterThan(20)
        ->and(Goal::query()->onlyOwnedBy($user)->count())->toBe(3)
        ->and(Snapshot::query()->onlyOwnedBy($user)->count())->toBe(4);
});

/**
 * Loading it twice would double every balance; loading it over real figures
 * would bury them.
 */
it('will not load the sample over an existing fleet', function () {
    $user = User::factory()->create();
    Holding::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('finance.sample.load'))->assertSessionHas('error');

    expect(Holding::query()->onlyOwnedBy($user)->count())->toBe(1);
});

it('clears only the signed-in user\'s fleet', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    app(SampleFleet::class)->load($user);
    app(SampleFleet::class)->load($other);

    $this->actingAs($user)->delete(route('finance.sample.clear'))->assertRedirect(route('finance.overview'));

    expect(Holding::query()->onlyOwnedBy($user)->count())->toBe(0)
        ->and(Flow::query()->onlyOwnedBy($user)->count())->toBe(0)
        ->and(Snapshot::query()->onlyOwnedBy($user)->count())->toBe(0)
        ->and(Profile::query()->onlyOwnedBy($user)->count())->toBe(0)
        ->and(Holding::query()->onlyOwnedBy($other)->count())->toBeGreaterThan(10);
});
