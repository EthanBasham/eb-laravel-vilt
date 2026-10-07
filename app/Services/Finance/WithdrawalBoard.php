<?php

namespace App\Services\Finance;

use App\Models\Finance\Scenario;
use App\Models\Finance\WithdrawalStrategy;
use App\Models\User;

/**
 * The Retirement Strategizer's withdrawals tab: every saved way of drawing the
 * accounts down, run over the same lifetime so they can be set side by side.
 *
 * A strategy chooses the order the three buckets are emptied in
 * (WithdrawalStrategy::kind), how much a retired year takes (its spending
 * rule), and the projection and rates it is run under.
 *
 * It is the conversion tab's model with the conversions taken out, and reads
 * the same household (ConversionBoard::world()), so the two tabs agree:
 *
 *  - Three buckets — taxable savings, traditional, Roth — each growing at
 *    what its own holdings expect unless the strategy sets one rate for all.
 *  - Each year's income and expenses come from the projection, before tax.
 *    The RMD and anything drawn from traditional are ordinary income, and the
 *    year is taxed whole on tables raised by the strategy's inflation rate.
 *  - While working, the year runs on the projection whatever the rule: its
 *    expenses, tax and the fleet's contributions are met from income, a
 *    shortfall is drawn in the strategy's order and a surplus is saved.
 *  - Once retired, the `projection` rule does the same without the
 *    contributions. The `fixed` and `percent` rules instead take a set amount
 *    from the accounts — the RMD counts towards it, and is taken in full even
 *    when it is more — and what the year leaves to spend is the result.
 *  - Money taken from traditional before 59½ owes the 10% penalty. IRMAA is
 *    charged from 65 on the income of two years before.
 *  - Growth and sales in the taxable bucket are not taxed.
 *
 * Every figure returned is in today's dollars.
 */
class WithdrawalBoard
{
    /** Medicare, and so IRMAA, begins here. */
    private const MEDICARE_AGE = 65;

    /** A year's premium is set by the income of this many years before. */
    private const IRMAA_LOOKBACK = 2;

    /** The first age, counted as the year turned, free of the early-withdrawal penalty (59½). */
    private const PENALTY_FREE_AGE = 60;

    /** The additional tax on an early withdrawal from a traditional account. */
    private const EARLY_PENALTY = 0.10;

    public function __construct(
        private Fleet $fleet,
        private ConversionBoard $conversions,
        private ScenarioBoard $scenarios,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $world = $this->conversions->world($user);
        $profile = $world['profile'];
        $flows = $this->fleet->flows($user);

        $strategies = WithdrawalStrategy::query()->onlyOwnedBy($user)->inDefaultOrder()->with('scenario.scenarioFlows')->get();

        // Each projection is worked out once, however many strategies build
        // on it. 0 is the flows as entered.
        $projections = [];

        foreach ($strategies as $strategy) {
            $projections[$strategy->scenario_id ?? 0] ??= $this->scenarios->yearly(
                $this->scenarios->rows($flows, $strategy->scenario?->scenarioFlows ?? collect(), $profile),
            );
        }

        $total = (float) array_sum($world['balances']);
        $defaultPercent = (float) config('finance.defaults.withdrawal_percent');

        return [
            'profile' => [
                'age' => $profile->age,
                'has_birth_date' => $profile->birth_date !== null,
                'retirement_age' => $profile->retirement_age,
                'rmd_start_age' => $profile->rmd_start_age,
                'life_expectancy' => $world['ages']['last'],
                'inflation_rate' => $profile->inflation_rate,
                'filing_status' => config("finance.tax.filing_statuses.{$world['status']}", $world['status']),
            ],
            'balances' => [...$world['balances'], 'total' => $total],
            'growth_rate' => $world['growth_rate'],
            'tax_year' => (int) config('finance.tax.year'),
            'kinds' => config('finance.withdrawal_strategies'),
            'spending_rules' => config('finance.spending_rules'),
            'fill_rates' => config('finance.conversion_fill_rates'),
            'default_percent' => $defaultPercent,
            // The classic figure on today's balance, to the nearest $100:
            // what a fixed-amount strategy opens on.
            'default_spending_amount' => round($total * $defaultPercent / 100, -2),
            'scenarios' => Scenario::query()->onlyOwnedBy($user)->inDefaultOrder()->get()->map->only(['id', 'name', 'bracket_inflation_rate'])->values(),
            'strategies' => $strategies->map(fn (WithdrawalStrategy $strategy): array => [
                ...$strategy->props,
                ...$this->simulate($strategy, $world, $projections[$strategy->scenario_id ?? 0]),
            ])->values(),
        ];
    }

    /**
     * One strategy, year by year.
     *
     * @param  array<string, mixed>  $world  ConversionBoard::world().
     * @param  array<int, array{year: int, age: int, income: float, expenses: float, taxed: array<string, float>}>  $years  The projection, keyed by year.
     * @return array{assumptions: array<string, mixed>, rows: list<array<string, float|int|null>>, summary: array<string, float|int|null>}
     */
    public function simulate(WithdrawalStrategy $strategy, array $world, array $years): array
    {
        ['status' => $status, 'ages' => $ages, 'federal' => $federal, 'tax_on' => $taxOn, 'divisors' => $divisors] = $world;

        $inflationRate = $strategy->inflation_rate ?? $strategy->scenario?->bracket_inflation_rate ?? $world['profile']->inflation_rate;
        $growthRates = $strategy->growth_rate !== null
            ? array_fill_keys(['deferred', 'free', 'taxable'], $strategy->growth_rate)
            : $world['growth_rates'];
        $growth = array_map(fn (float $rate): float => 1 + max(-95.0, $rate) / 100, $growthRates);
        $kind = config("finance.withdrawal_strategies.{$strategy->kind}") ?? config('finance.withdrawal_strategies.conventional');
        $taxations = config('finance.flow_taxations');
        $oldestDivisor = array_key_last($divisors);
        $contributions = $world['contributions'];

        $balances = $world['balances'];

        $rows = [];
        $magi = [];
        $openingMagi = null;
        $shortAtAge = null;
        $index = 1.0;

        for ($age = $ages['now']; $age <= $ages['last']; $age++) {
            $year = $ages['birth_year'] + $age;
            $isRetired = $age >= $ages['retirement'];
            $isPenalised = $age < self::PENALTY_FREE_AGE;

            ['income' => $cashIncome, 'expenses' => $expenses, 'taxed' => $taxed] = $years[$year] ?? ['income' => 0.0, 'expenses' => 0.0, 'taxed' => []];

            $gains = 0.0;
            $ordinary = 0.0;

            foreach ($taxed as $taxation => $amount) {
                if (($taxations[$taxation]['schedule'] ?? null) === 'capital_gains') {
                    $gains += $amount;
                } else {
                    $ordinary += $amount;
                }
            }

            $opening = array_sum($balances);

            // The RMD is worked on the balance the year opened with.
            $rmd = 0.0;

            if ($age >= $ages['rmd_start'] && $balances['deferred'] > 0) {
                $rmd = $balances['deferred'] / $divisors[min($age, $oldestDivisor)];
            }

            $balances['deferred'] -= $rmd;

            // As ConversionBoard: the years before the plan are taken to
            // have looked like its first, RMD included.
            $openingMagi ??= ($ordinary + $gains + $rmd) / $index;

            // This year's premium was set by the income of two years ago,
            // against this year's tiers. See ConversionBoard for the detail.
            $irmaa = 0.0;

            if ($age >= self::MEDICARE_AGE) {
                $lookback = isset($magi[$year - self::IRMAA_LOOKBACK]) ? $magi[$year - self::IRMAA_LOOKBACK] / $index : $openingMagi;
                $months = $age === self::MEDICARE_AGE && $ages['birth_month'] !== null ? 13 - $ages['birth_month'] : 12;
                $irmaa = $this->conversions->irmaaSurcharge($this->conversions->irmaaTier($lookback, $world['irmaa']), $world) * $index * $months / 12;
            }

            // Everything from the retirement accounts is ordinary income, and
            // traditional money taken before 59½ owes the penalty besides.
            $taxWith = fn (float $fromTraditional): float => $taxOn([...$taxed, 'income_only' => ($taxed['income_only'] ?? 0.0) + $rmd + $fromTraditional], $index, $age)['total']
                + ($isPenalised ? $fromTraditional * self::EARLY_PENALTY : 0.0);

            // How much traditional money still fits under the top of the
            // bracket a bracket-filling order draws up to.
            $room = INF;

            if ($kind['fills'] ?? false) {
                $rate = $strategy->fill_rate ?? $federal->marginalRate($ordinary + $rmd, $status, $index, $age);
                $top = $federal->grossCeiling($rate, $status, $index, $age);
                $room = $top === null ? INF : max(0.0, $top - $ordinary - $rmd);
            }

            $saving = $isRetired ? 0.0 : array_sum($contributions);
            $rule = $isRetired ? $strategy->spending_rule : 'projection';

            if ($rule === 'projection') {
                /*
                 * What has to be found depends on the tax, and the tax on how
                 * much of it is found from traditional. Settled by going
                 * round until the traditional draw stops moving.
                 */
                $draw = ['taxable' => 0.0, 'deferred' => 0.0, 'free' => 0.0, 'unmet' => 0.0];

                for ($pass = 0; $pass < 25; $pass++) {
                    $need = $expenses + $taxWith($draw['deferred']) + $irmaa + $saving - $cashIncome - $rmd;
                    $next = $this->allocate(max(0.0, $need), $balances, $kind, $room);

                    if (abs($next['deferred'] - $draw['deferred']) < 0.5) {
                        $draw = $next;

                        break;
                    }

                    $draw = $next;
                }

                $tax = $taxWith($draw['deferred']);
                $need = $expenses + $tax + $irmaa + $saving - $cashIncome - $rmd;
                $spendable = $expenses - $draw['unmet'];

                // A year with money to spare saves it.
                if ($need < 0) {
                    $balances['taxable'] -= $need;
                }
            } else {
                $target = $rule === 'percent'
                    ? $opening * ($strategy->spending_percent ?? 0.0) / 100
                    : ($strategy->spending_amount ?? 0.0) * $index;

                // The RMD is taken whatever the rule says, and counts
                // towards it.
                $draw = $this->allocate(max(0.0, $target - $rmd), $balances, $kind, $room);
                $draw['unmet'] = 0.0;
                $tax = $taxWith($draw['deferred']);
                $spendable = $cashIncome + $rmd + $draw['taxable'] + $draw['deferred'] + $draw['free'] - $tax - $irmaa;
            }

            if ($draw['unmet'] > 1 && $shortAtAge === null) {
                $shortAtAge = $age;
            }

            $balances['taxable'] -= $draw['taxable'];
            $balances['deferred'] -= $draw['deferred'];
            $balances['free'] -= $draw['free'];

            if (! $isRetired) {
                foreach ($contributions as $bucket => $contribution) {
                    $balances[$bucket] += $contribution;
                }
            }

            foreach ($balances as $bucket => $balance) {
                $balances[$bucket] = max(0.0, $balance) * $growth[$bucket];
            }

            $bracketIncome = $ordinary + $rmd + $draw['deferred'];
            $magi[$year] = $bracketIncome + $gains;

            // Year-end balances are deflated by the *next* year's index,
            // since that is the price level they will be spent at.
            $endIndex = $index * (1 + $inflationRate / 100);

            $rows[] = [
                'age' => $age,
                'year' => $year,
                'is_retired' => $isRetired,
                'income' => round($cashIncome / $index),
                'expenses' => round($expenses / $index),
                // What the year had to spend: the projection's expenses as
                // far as they were met, or what a set withdrawal left.
                'spendable' => round($spendable / $index),
                'rmd' => round($rmd / $index),
                'from_taxable' => round($draw['taxable'] / $index),
                'from_traditional' => round($draw['deferred'] / $index),
                'from_roth' => round($draw['free'] / $index),
                'withdrawn' => round(($rmd + $draw['taxable'] + $draw['deferred'] + $draw['free']) / $index),
                'unmet' => round($draw['unmet'] / $index),
                'tax' => round($tax / $index),
                'irmaa' => round($irmaa / $index),
                'tax_and_irmaa' => round($tax / $index) + round($irmaa / $index),
                'marginal_rate' => $federal->marginalRate(max(0.0, $bracketIncome - 1), $status, $index, $age),
                'traditional' => round($balances['deferred'] / $endIndex),
                'roth' => round($balances['free'] / $endIndex),
                'taxable' => round($balances['taxable'] / $endIndex),
                'total_balance' => round($balances['deferred'] / $endIndex) + round($balances['free'] / $endIndex) + round($balances['taxable'] / $endIndex),
            ];

            $index = $endIndex;
        }

        $last = $rows[array_key_last($rows)];
        $retired = array_values(array_filter($rows, fn (array $row): bool => $row['is_retired']));
        $lifetimeTax = array_sum(array_column($rows, 'tax'));
        $lifetimeIrmaa = array_sum(array_column($rows, 'irmaa'));

        return [
            // The settings as they were resolved, blanks filled in.
            'assumptions' => [
                'inflation_rate' => $inflationRate,
                'growth_rate' => $strategy->growth_rate ?? $world['growth_rate'],
                'scenario_name' => $strategy->scenario?->name,
            ],
            'rows' => $rows,
            'summary' => [
                'withdrawn' => array_sum(array_column($retired, 'withdrawn')),
                'from_taxable' => array_sum(array_column($retired, 'from_taxable')),
                'from_traditional' => array_sum(array_column($retired, 'from_traditional')) + array_sum(array_column($retired, 'rmd')),
                'from_roth' => array_sum(array_column($retired, 'from_roth')),
                'total_rmd' => array_sum(array_column($rows, 'rmd')),
                'spendable' => array_sum(array_column($retired, 'spendable')),
                // The leanest retired year: what a set withdrawal can drop to.
                'least_spendable' => $retired === [] ? null : min(array_column($retired, 'spendable')),
                'lifetime_tax' => $lifetimeTax,
                'irmaa' => $lifetimeIrmaa,
                'tax_and_irmaa' => $lifetimeTax + $lifetimeIrmaa,
                'peak_marginal_rate' => max(array_column($rows, 'marginal_rate')),
                'ending_traditional' => $last['traditional'],
                'ending_roth' => $last['roth'],
                'ending_taxable' => $last['taxable'],
                'ending_balance' => $last['total_balance'],
                'short_at_age' => $shortAtAge,
            ],
        ];
    }

    /**
     * Where an amount is taken from, by the strategy's order.
     *
     * @param  array{deferred: float, free: float, taxable: float}  $balances
     * @param  array<string, mixed>  $kind  A row of config `finance.withdrawal_strategies`.
     * @param  float  $room  The traditional money a bracket-filling order may take first; INF otherwise.
     * @return array{taxable: float, deferred: float, free: float, unmet: float}
     */
    private function allocate(float $need, array $balances, array $kind, float $room): array
    {
        $draw = ['taxable' => 0.0, 'deferred' => 0.0, 'free' => 0.0];

        if ($need <= 0) {
            return [...$draw, 'unmet' => 0.0];
        }

        $available = array_map(fn (float $balance): float => max(0.0, $balance), $balances);

        // A share of each, by what it holds. Nothing is left to take in
        // order afterwards except when the whole of it falls short.
        if ($kind['proportional'] ?? false) {
            $total = array_sum($available);

            foreach ($available as $bucket => $balance) {
                $draw[$bucket] = $total > 0 ? min($balance, $need * $balance / $total) : 0.0;
            }

            return [...$draw, 'unmet' => max(0.0, $need - array_sum($draw))];
        }

        // Traditional money up to the top of the bracket comes first.
        if ($kind['fills'] ?? false) {
            $draw['deferred'] = min($need, $available['deferred'], $room);
            $need -= $draw['deferred'];
        }

        foreach ($kind['order'] as $bucket) {
            $taken = min($need, $available[$bucket] - $draw[$bucket]);

            $draw[$bucket] += $taken;
            $need -= $taken;
        }

        return [...$draw, 'unmet' => max(0.0, $need)];
    }
}
