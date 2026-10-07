<?php

namespace App\Services\Finance;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use App\Jobs\Finance\RunConversionMonteCarlo;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\MonteCarloRun;
use App\Models\User;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The conversion strategies run through many random markets instead of one
 * steady one: how often each lasts, and the spread of what it leaves.
 *
 * Each market is a path of yearly returns and yearly inflation. A strategy's
 * own growth and inflation rates are the averages; the user's two volatility
 * settings are the standard deviations either side, drawn from a normal
 * distribution — the plain "average plus volatility" model, which ignores
 * fat tails and the way bad years cluster.
 *
 * Every strategy runs through the *same* markets: the random draws are made
 * once, as standard scores, and each strategy scales them by its own rates.
 * So a difference between two strategies is the strategy, not the luck of
 * the draw — which is what makes "beats no conversion in 68% of markets"
 * mean something. The draws are seeded, so the same settings give the same
 * answer every time the page is opened.
 *
 * A run small enough for a page load (config `finance.monte_carlo.page_limit`
 * simulations) is worked out when the page asks; a bigger one goes to a
 * queued job, and its results are kept with a hash of what they were run on.
 */
class ConversionMonteCarlo
{
    /** Nor does deflation run deeper than this. */
    private const WORST_INFLATION = -5.0;

    /** The figures of a run's summary that describe() reports on. */
    private const REPORTED = ['short_at_age', 'ending_after_heir_tax', 'tax_with_heirs', 'lifetime_tax', 'irmaa', 'converted'];

    /** The outcomes reported for each figure: a bad, a typical and a good one. */
    private const PERCENTILES = ['p10' => 10, 'p50' => 50, 'p90' => 90];

    public function __construct(private ConversionBoard $board) {}

    /**
     * What the page shows: the settings, whether the runs happen now or in
     * the background, and the results — live, stored, or none yet.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $settings = MonteCarloRun::for($user);
        $context = $this->board->context($user);
        $simulations = $settings->runs * $context['strategies']->count();
        $limit = (int) config('finance.monte_carlo.page_limit');

        $state = [
            'settings' => $settings->only(['runs', 'return_volatility', 'inflation_volatility', 'seed']),
            'simulations' => $simulations,
            'page_limit' => $limit,
            'in_background' => $simulations > $limit,
        ];

        if ($simulations === 0) {
            return [...$state, 'status' => 'off', 'is_current' => false, 'results' => null];
        }

        if ($simulations <= $limit) {
            return [...$state, 'status' => 'live', 'is_current' => true, 'results' => $this->run($context, $settings)];
        }

        // Stored results are shown even when they no longer match, so the
        // page has something while a new run is under way; `is_current`
        // says whether they still describe the strategies.
        return [
            ...$state,
            'status' => $settings->status,
            'is_current' => $settings->results !== null && $settings->inputs_hash === $this->hash($context, $settings),
            'results' => $settings->results,
            'ran_at' => $settings->ran_at?->toIso8601String(),
            'error' => $settings->error,
        ];
    }

    /**
     * Whether these settings are too big for a page load, and so need a
     * background run to have any results at all.
     */
    public function needsBackground(User $user, MonteCarloRun $settings): bool
    {
        return $settings->runs * ConversionStrategy::query()->onlyOwnedBy($user)->onlyCompared()->count() > (int) config('finance.monte_carlo.page_limit');
    }

    /**
     * Hands a run to the queue. A run already waiting is not doubled up:
     * the job is unique per user, and works from the settings and strategies
     * as they are when it starts.
     */
    public function queue(MonteCarloRun $settings): void
    {
        $settings->fill(['status' => 'queued', 'error' => null])->save();

        RunConversionMonteCarlo::dispatch($settings->user_id);
    }

    /**
     * Hands a run to the queue only when the settings are too big for a page
     * load; a smaller one is worked out by the page itself. Says which.
     */
    public function queueIfNeeded(User $user, MonteCarloRun $settings): bool
    {
        if (! $this->needsBackground($user, $settings)) {
            return false;
        }

        $this->queue($settings);

        return true;
    }

    /**
     * The queued job's work: run everything and keep the results, stamped
     * with what they were run on.
     */
    public function runInBackground(User $user): void
    {
        $settings = MonteCarloRun::for($user);
        $settings->fill(['status' => 'running'])->save();

        $context = $this->board->context($user);

        $settings->fill([
            'status' => 'done',
            'results' => $this->run($context, $settings),
            'inputs_hash' => $this->hash($context, $settings),
            'ran_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * Every strategy through the same markets.
     *
     * @param  array{world: array<string, mixed>, strategies: Collection<int, ConversionStrategy>, years: array<int, array<int, array<string, mixed>>>}  $context
     * @return array{runs: int, baseline_id: int|null, strategies: array<int, array<string, mixed>>}
     */
    public function run(array $context, MonteCarloRun $settings): array
    {
        ['world' => $world, 'strategies' => $strategies, 'years' => $years] = $context;

        $steps = $world['ages']['last'] - $world['ages']['now'] + 1;
        $draws = $this->draws($settings->runs, $steps, $settings->seed);

        // What "beats no conversion" is measured against: the first strategy
        // that does not convert, if there is one.
        $baselineId = $strategies->firstWhere('kind', 'none')?->id;

        $outcomes = [];

        foreach ($strategies as $strategy) {
            $inflation = $this->board->inflationRate($strategy, $world);

            foreach ($draws as $run => [$returnScores, $inflationScores]) {
                $path = [
                    // Points either side of each bucket's average return.
                    'shocks' => array_map(fn (float $score): float => $settings->return_volatility * $score, $returnScores),
                    'inflation' => array_map(fn (float $score): float => max(self::WORST_INFLATION, $inflation + $settings->inflation_volatility * $score), $inflationScores),
                ];

                // In today's dollars: each market has its own inflation, so
                // only one year's prices let them be ranked against each other.
                ['rows' => $rows, 'summary' => $summary] = $this->board->simulate($strategy, $world, $years[$strategy->id], $path, inTodaysDollars: true);

                // Only the figures describe() reads: a full summary for every
                // run of every strategy is hundreds of megabytes at the most
                // the settings allow.
                $outcomes[$strategy->id][$run] = [
                    'summary' => Arr::only($summary, self::REPORTED),
                    'balances' => array_column($rows, 'total_balance'),
                ];
            }
        }

        $ages = range($world['ages']['now'], $world['ages']['last']);

        return [
            'runs' => $settings->runs,
            'baseline_id' => $baselineId,
            'strategies' => collect($outcomes)->map(fn (array $runs, int $id): array => $this->describe($runs, $id === $baselineId ? null : ($outcomes[$baselineId] ?? null), $ages))->all(),
        ];
    }

    /**
     * One strategy's runs, reduced to what the page shows.
     *
     * @param  list<array{summary: array<string, mixed>, balances: list<float>}>  $runs
     * @param  list<array{summary: array<string, mixed>, balances: list<float>}>|null  $baseline  The no-conversion strategy's runs, market for market.
     * @param  list<int>  $ages
     * @return array<string, mixed>
     */
    private function describe(array $runs, ?array $baseline, array $ages): array
    {
        $figure = fn (string $key): array => $this->percentiles(array_map(fn (array $run): float => (float) $run['summary'][$key], $runs));
        $lasts = count(array_filter($runs, fn (array $run): bool => $run['summary']['short_at_age'] === null));

        $beats = null;

        if ($baseline !== null) {
            $wins = count(array_filter(array_keys($runs), fn (int $run): bool => $runs[$run]['summary']['ending_after_heir_tax'] > $baseline[$run]['summary']['ending_after_heir_tax']));
            $beats = round($wins / count($runs) * 100, 1);
        }

        $shortAges = array_filter(array_map(fn (array $run): ?int => $run['summary']['short_at_age'], $runs));

        return [
            'success_rate' => round($lasts / count($runs) * 100, 1),
            // The age the money runs out at in the typical market that runs
            // short, when any do.
            'typical_short_age' => $shortAges === [] ? null : (int) round($this->percentile(array_values($shortAges), 50)),
            'beats_baseline' => $beats,
            'ending_after_heir_tax' => $figure('ending_after_heir_tax'),
            'tax_with_heirs' => $figure('tax_with_heirs'),
            'lifetime_tax' => $figure('lifetime_tax'),
            'irmaa' => $figure('irmaa'),
            'converted' => $figure('converted'),
            // Everything left in the three buckets each year: the fan chart.
            'balances' => array_map(fn (int $age, int $step): array => [
                'age' => $age,
                ...$this->percentiles(array_column(array_column($runs, 'balances'), $step)),
            ], $ages, array_keys($ages)),
        ];
    }

    /**
     * @param  list<float>  $values
     * @return array{p10: float, p50: float, p90: float}
     */
    private function percentiles(array $values): array
    {
        return array_map(fn (int $percentile): float => round($this->percentile($values, $percentile)), self::PERCENTILES);
    }

    /**
     * The value a percentage of the way along sorted values, interpolating
     * between the two either side.
     *
     * @param  list<float|int>  $sorted
     */
    private function percentile(array $sorted, float $percentile): float
    {
        sort($sorted);
        $position = (count($sorted) - 1) * $percentile / 100;
        $below = (int) floor($position);
        $above = (int) ceil($position);

        return $sorted[$below] + ($sorted[$above] - $sorted[$below]) * ($position - $below);
    }

    /**
     * The random draws every strategy shares: for each run, a standard score
     * for each year's return and another for each year's inflation.
     *
     * Box–Muller turns two uniform draws into two independent normal ones.
     *
     * @return list<array{0: list<float>, 1: list<float>}>
     */
    private function draws(int $runs, int $steps, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $normal = function () use ($random): float {
            $first = 1.0 - $random->nextFloat();

            return sqrt(-2 * log($first)) * cos(2 * M_PI * $random->nextFloat());
        };

        $draws = [];

        for ($run = 0; $run < $runs; $run++) {
            $returns = [];
            $inflation = [];

            for ($step = 0; $step < $steps; $step++) {
                $returns[] = $normal();
                $inflation[] = $normal();
            }

            $draws[] = [$returns, $inflation];
        }

        return $draws;
    }

    /**
     * A fingerprint of everything a run reads: the settings, the household,
     * the strategies and their projections. Stored results whose fingerprint
     * no longer matches are out of date.
     *
     * @param  array{world: array<string, mixed>, strategies: Collection<int, ConversionStrategy>, years: array<int, array<int, array<string, mixed>>>}  $context
     */
    private function hash(array $context, MonteCarloRun $settings): string
    {
        ['world' => $world, 'strategies' => $strategies, 'years' => $years] = $context;

        return hash('sha256', json_encode([
            $settings->only(['runs', 'return_volatility', 'inflation_volatility', 'seed']),
            collect($world)->except(['profile', 'federal', 'tax_on'])->all(),
            collect($world['profile']->getAttributes())->except(['id', 'created_at', 'updated_at'])->all(),
            $strategies->map(fn (ConversionStrategy $strategy): array => [...$strategy->props, 'inflation' => $this->board->inflationRate($strategy, $world)])->all(),
            $years,
            now()->year,
        ]));
    }
}
