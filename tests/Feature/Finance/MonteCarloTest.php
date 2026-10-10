<?php

use Illuminate\Support\Facades\Queue;
use App\Jobs\Finance\RunConversionMonteCarlo;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\MonteCarloRun;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\User;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\ConversionMonteCarlo;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * A retiree of 68, planning to 80, with $1,000,000 traditional and
 * $2,000,000 in a brokerage account, growing at 5% with 2.5% inflation.
 */
function monteCarloRetiree(): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1958-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 2.5]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 5]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 2_000_000, 'annual_rate' => 5]);

    return $user;
}

/**
 * The results of a run with these settings, straight from the service.
 *
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function monteCarlo(User $user, array $settings = []): array
{
    $board = app(ConversionBoard::class);

    return app(ConversionMonteCarlo::class)->run($board->context($user), new MonteCarloRun(['runs' => 40, 'return_volatility' => 12, 'inflation_volatility' => 1, 'seed' => 1, ...$settings]));
}

/** The id of the one column a strategy has in the report: what its results are keyed by. */
function columnOf(ConversionStrategy $strategy): int
{
    return $strategy->reportEntries()->sole()->id;
}

// The runs

/**
 * Expenses are projected at the average inflation. A market whose prices run
 * at 10% a year instead carries them up with it, so in today's dollars they
 * hold still rather than shrinking.
 */
it('moves the projected expenses with a market\'s own inflation', function () {
    $user = monteCarloRetiree();
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual', 'annual_growth_rate' => 2.5]);
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $board = app(ConversionBoard::class);
    ['world' => $world, 'years' => $years] = $board->context($user);
    $steps = 13;

    $rows = $board->simulate($strategy, $world, $years[columnOf($strategy)], ['shocks' => array_fill(0, $steps, 0.0), 'inflation' => array_fill(0, $steps, 10.0)], inTodaysDollars: true)['rows'];

    expect($rows[1]['expenses'])->toEqual(60_000)
        ->and($rows[5]['expenses'])->toEqual(60_000);
});

/**
 * With no volatility every market is the steady one, so every run is the
 * single run the rest of the page shows — in today's dollars, as the runs
 * are — and every percentile agrees with it.
 */
it('agrees with the steady plan when the markets do not vary', function () {
    $user = monteCarloRetiree();
    $strategy = ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id]);

    $board = app(ConversionBoard::class);
    ['world' => $world, 'years' => $years] = $board->context($user);
    $steady = $board->simulate($strategy, $world, $years[columnOf($strategy)], inTodaysDollars: true);
    $results = monteCarlo($user, ['runs' => 5, 'return_volatility' => 0, 'inflation_volatility' => 0])['strategies'][columnOf($strategy)];

    expect($results['success_rate'])->toEqual(100)
        ->and($results['inheritable'])->toEqual(array_fill_keys(['p10', 'p50', 'p90'], $steady['summary']['inheritable']))
        ->and($results['leftover_tax']['p50'])->toEqual($steady['summary']['leftover_tax'])
        ->and($results['balances'][12])->toEqual(['age' => 80, 'p10' => $steady['rows'][12]['total_balance'], 'p50' => $steady['rows'][12]['total_balance'], 'p90' => $steady['rows'][12]['total_balance']]);
});

it('spreads the outcomes, worst to best, when the markets do vary', function () {
    $user = monteCarloRetiree();
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    ['p10' => $bad, 'p50' => $typical, 'p90' => $good] = monteCarlo($user)['strategies'][columnOf($strategy)]['inheritable'];

    expect($bad)->toBeLessThan($typical)
        ->and($typical)->toBeLessThan($good);
});

it('draws the same markets from the same seed, and different ones from another', function () {
    $user = monteCarloRetiree();
    ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    expect(monteCarlo($user, ['seed' => 7]))->toEqual(monteCarlo($user, ['seed' => 7]))
        ->and(monteCarlo($user, ['seed' => 8]))->not->toEqual(monteCarlo($user, ['seed' => 7]));
});

/**
 * Two identical strategies in the same markets finish identically, which
 * they could only do if every strategy is put through the same draws.
 */
it('puts every strategy through the same markets', function () {
    $user = monteCarloRetiree();
    $first = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);
    $twin = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $results = monteCarlo($user);

    expect($results['strategies'][columnOf($twin)]['inheritable'])->toEqual($results['strategies'][columnOf($first)]['inheritable'])
        // A tie is not a win.
        ->and($results['strategies'][columnOf($twin)]['beats_baseline'])->toEqual(0);
});

it('measures how often a strategy beats not converting, against the first that does not', function () {
    $user = monteCarloRetiree();
    $none = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);
    $even = ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id]);

    $results = monteCarlo($user);

    expect($results['strategies'][columnOf($none)]['beats_baseline'])->toBeNull()
        ->and($results['strategies'][columnOf($even)]['beats_baseline'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});

it('has nothing to beat when no strategy leaves the money where it is', function () {
    $user = monteCarloRetiree();
    $even = ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id]);

    expect(monteCarlo($user)['strategies'][columnOf($even)]['beats_baseline'])->toBeNull();
});

it('measures a strategy only against not converting on its own projection', function () {
    $user = monteCarloRetiree();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);
    $elsewhere = ConversionStrategy::factory()->reported($scenario)->ofKind('even')->create(['user_id' => $user->id]);

    expect(monteCarlo($user)['strategies'][columnOf($elsewhere)]['beats_baseline'])->toBeNull();
});

it('reports how often the money lasts, and when it typically does not', function () {
    $user = monteCarloRetiree();
    // About what the accounts can bear in a steady market, so the bad ones run short.
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 300_000, 'frequency' => 'annual', 'annual_growth_rate' => 2.5]);
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $results = monteCarlo($user, ['runs' => 60, 'return_volatility' => 20])['strategies'][columnOf($strategy)];

    expect($results['success_rate'])->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($results['typical_short_age'])->toBeGreaterThan(68)->toBeLessThanOrEqual(80);
});

// The page

it('works a small run out after the page loads', function () {
    $user = monteCarloRetiree();
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page
            ->missing('monte_carlo')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('monte_carlo.status', 'live')
                ->where('monte_carlo.simulations', 100)
                ->where('monte_carlo.in_background', false)
                ->where('monte_carlo.results.runs', 100)
                ->has('monte_carlo.results.strategies.'.columnOf($strategy).'.balances', 13)));

    expect(MonteCarloRun::query()->count())->toBe(0);
});

it('leaves a run too big for the page to the background, and works none out on the page', function () {
    config(['finance.monte_carlo.page_limit' => 50]);
    $user = monteCarloRetiree();
    ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where('monte_carlo.in_background', true)
            ->where('monte_carlo.status', 'idle')
            ->where('monte_carlo.results', null)));
});

it('is off at no runs', function () {
    $user = monteCarloRetiree();
    ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);
    MonteCarloRun::factory()->create(['user_id' => $user->id, 'runs' => 0]);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->where('monte_carlo.status', 'off')));
});

// Settings and background runs

it('saves the settings, and queues a run only when it is too big for the page', function () {
    Queue::fake();
    config(['finance.monte_carlo.page_limit' => 500]);
    $user = monteCarloRetiree();
    ConversionStrategy::factory()->reported()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.retirement.monte-carlo.update'), ['runs' => 250, 'return_volatility' => 15, 'inflation_volatility' => 2])->assertSessionHasNoErrors();
    Queue::assertNothingPushed();
    expect(MonteCarloRun::query()->sole()->only(['runs', 'return_volatility', 'inflation_volatility']))->toBe(['runs' => 250, 'return_volatility' => 15.0, 'inflation_volatility' => 2.0]);

    $this->actingAs($user)->put(route('finance.retirement.monte-carlo.update'), ['runs' => 251, 'return_volatility' => 15, 'inflation_volatility' => 2]);
    Queue::assertPushed(RunConversionMonteCarlo::class, fn (RunConversionMonteCarlo $job) => $job->userId === $user->id);
    expect(MonteCarloRun::query()->sole()->status)->toBe('queued');
});

it('keeps a background run\'s results, and says when they no longer describe the strategies', function () {
    config(['finance.monte_carlo.page_limit' => 10]);
    $user = monteCarloRetiree();
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);
    MonteCarloRun::factory()->create(['user_id' => $user->id, 'runs' => 20]);

    // The queue runs jobs inline under test, so the run finishes here.
    $this->actingAs($user)->post(route('finance.retirement.monte-carlo.run'));

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where('monte_carlo.status', 'done')
            ->where('monte_carlo.is_current', true)
            ->where('monte_carlo.results.runs', 20)
            ->has('monte_carlo.results.strategies.'.columnOf($strategy))));

    $strategy->update(['kind' => 'even']);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where('monte_carlo.is_current', false)
            ->where('monte_carlo.results.runs', 20)));
});

it('draws a new set of markets on request', function () {
    $user = monteCarloRetiree();

    $this->actingAs($user)->post(route('finance.retirement.monte-carlo.reshuffle'));
    $this->actingAs($user)->post(route('finance.retirement.monte-carlo.reshuffle'));

    expect(MonteCarloRun::query()->sole()->seed)->toBe(3);
});

it('records a background run that failed', function () {
    $user = monteCarloRetiree();
    MonteCarloRun::factory()->create(['user_id' => $user->id, 'status' => 'running']);

    (new RunConversionMonteCarlo($user->id))->failed(new RuntimeException('Out of memory'));

    expect(MonteCarloRun::query()->sole()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'Out of memory']);
});

it('refuses settings it cannot run', function (array $payload, string $field) {
    $this->actingAs(monteCarloRetiree())
        ->put(route('finance.retirement.monte-carlo.update'), [...['runs' => 100, 'return_volatility' => 12, 'inflation_volatility' => 1], ...$payload])
        ->assertSessionHasErrors($field);

    expect(MonteCarloRun::query()->count())->toBe(0);
})->with([
    'more runs than allowed' => [['runs' => 10_001], 'runs'],
    'negative volatility' => [['return_volatility' => -1], 'return_volatility'],
    'wild inflation volatility' => [['inflation_volatility' => 25], 'inflation_volatility'],
]);
