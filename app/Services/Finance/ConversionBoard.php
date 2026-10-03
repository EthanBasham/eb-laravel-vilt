<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\User;

/**
 * The Retirement Strategizer's conversion tab: every saved strategy for moving
 * traditional money to Roth, run over the same lifetime so they can be set
 * side by side.
 *
 * A strategy chooses four things: how to convert (ConversionStrategy::kind),
 * which projection of income and expenses to build on, how fast prices and the
 * tax tables rise, and who inherits. Everything else is the household's own.
 *
 * The model, and so its limits:
 *
 *  - Three buckets: tax-deferred (traditional), tax-free (Roth, HSA) and
 *    taxable (the investable accounts that are neither). One growth rate.
 *  - Each year's income and expenses come from the projection
 *    (ScenarioBoard), before tax. The RMD, any conversion and any traditional
 *    withdrawal are added as ordinary income, and the year is taxed whole by
 *    TaxCalculator::flowTaxFor() on tables raised by the strategy's inflation
 *    rate.
 *  - A conversion's tax is found from outside the conversion unless the
 *    strategy has some or all of it withheld from the converted money, in
 *    which case that much less reaches the Roth.
 *  - While working, the projection's income is assumed to cover its expenses
 *    and its own tax, and the fleet's monthly contributions go in; only the
 *    *extra* tax a conversion causes, and any IRMAA, has to be found. Once
 *    retired, expenses plus tax plus IRMAA are met from income and the RMD
 *    first, then the taxable bucket, then traditional, then Roth, and a
 *    surplus is saved to the taxable bucket.
 *  - IRMAA is charged from 65, per person (two on a joint return), on the
 *    income of two years before, with the tiers rising with inflation. Income
 *    for that purpose is taxed income plus the RMD, conversion and withdrawal:
 *    the untaxed part of Social Security is not added back. A strategy that
 *    stays inside an IRMAA tier does so for the conversion alone: a traditional
 *    withdrawal made later that year to cover spending can still cross it.
 *  - Heirs are taken to draw an inherited traditional balance in ten equal
 *    parts on top of their own income, as a single filer on the federal
 *    tables. No growth inside those ten years, no state tax. A charity pays
 *    nothing.
 *  - Growth in the taxable bucket is not itself taxed, and the Roth five-year
 *    rules are ignored.
 *
 * Every figure returned is in today's dollars, deflated by the strategy's own
 * inflation rate. That is what lets a tax bracket be one flat line on a chart.
 */
class ConversionBoard
{
    /** Medicare, and so IRMAA, begins here. */
    private const MEDICARE_AGE = 65;

    /** A year's premium is set by the income of this many years before. */
    private const IRMAA_LOOKBACK = 2;

    /** The years an heir has to empty an inherited account. */
    private const INHERITED_YEARS = 10;

    /** The usual pre-RMD window, for a strategy that does not name its own. */
    private const WINDOW = [65, 72];

    public function __construct(
        private Fleet $fleet,
        private TaxCalculator $tax,
        private ScenarioBoard $scenarios,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        ['world' => $world, 'strategies' => $strategies, 'years' => $years] = $this->context($user);
        $profile = $world['profile'];
        $status = $world['status'];

        return [
            'profile' => [
                'age' => $profile->age,
                'has_birth_date' => $profile->birth_date !== null,
                'retirement_age' => $profile->retirement_age,
                'rmd_start_age' => $profile->rmd_start_age,
                'life_expectancy' => $world['ages']['last'],
                'inflation_rate' => $profile->inflation_rate,
                'filing_status' => config("finance.tax.filing_statuses.{$status}", $status),
                'persons' => $world['persons'],
            ],
            'balances' => $world['balances'],
            'growth_rate' => $world['growth_rate'],
            'tax_year' => (int) config('finance.tax.year'),
            'kinds' => config('finance.conversion_strategies'),
            'fill_rates' => config('finance.conversion_fill_rates'),
            'tax_payments' => config('finance.conversion_tax_payments'),
            'default_heir_income' => (float) config('finance.defaults.heir_income'),
            // The ages each kind runs at when a strategy leaves them blank,
            // so the form can show them rather than an empty field.
            'defaults' => collect(config('finance.conversion_strategies'))
                ->map(fn (array $kind, string $key): ?array => $this->window($key, null, null, $world['ages']))
                ->all(),
            'scenarios' => Scenario::query()->onlyOwnedBy($user)->inDefaultOrder()->get()->map->only(['id', 'name', 'bracket_inflation_rate'])->values(),
            'brackets' => $this->bracketLines($world),
            'irmaa_tiers' => $this->irmaaLines($world),
            'strategies' => $strategies->map(fn (ConversionStrategy $strategy): array => [
                ...$strategy->props,
                ...$this->simulate($strategy, $world, $years[$strategy->id]),
            ])->values(),
        ];
    }

    /**
     * Everything a run needs that no strategy changes, worked out once: the
     * household (`world`), the user's strategies, and the projection each
     * builds on (`years`, keyed by strategy id).
     *
     * Public for the Monte Carlo runs, which simulate each strategy many
     * times over the same context.
     *
     * @return array{world: array<string, mixed>, strategies: Collection<int, ConversionStrategy>, years: array<int, array<int, array<string, mixed>>>}
     */
    public function context(User $user): array
    {
        $profile = Profile::for($user);
        // Leaves, so the accounts inside a compound retirement account are
        // each counted, under the tax treatment they inherit from it.
        $investable = $this->fleet->leaves($this->fleet->holdings($user))->filter->is_investable;
        $flows = $this->fleet->flows($user);
        $invested = (float) $investable->sum->value;
        $federal = $this->tax->forProfile($profile);
        $status = $profile->filing_status;

        $bucket = fn (string $treatment): Collection => $investable->where('tax_treatment', $treatment);

        $world = [
            'profile' => $profile,
            'status' => $status,
            'ages' => [
                'now' => $profile->age,
                'last' => max($profile->age, $profile->life_expectancy),
                'retirement' => $profile->retirement_age,
                'rmd_start' => $profile->rmd_start_age,
                'birth_year' => $profile->birth_year,
            ],
            'balances' => [
                'deferred' => (float) $bucket('deferred')->sum->value,
                'free' => (float) $bucket('free')->sum->value,
                'taxable' => (float) $bucket('taxable')->sum->value,
            ],
            'contributions' => [
                'deferred' => 12 * (float) $bucket('deferred')->sum('monthly_contribution'),
                'free' => 12 * (float) $bucket('free')->sum('monthly_contribution'),
                'taxable' => 12 * (float) $bucket('taxable')->sum('monthly_contribution'),
            ],
            'growth_rate' => $invested > 0
                ? round($investable->sum(fn (Holding $holding): float => $holding->value * $holding->expected_rate) / $invested, 1)
                : (float) config('finance.defaults.market_return'),
            'federal' => $federal,
            'tax_on' => $this->tax->flowTaxFor($profile),
            'divisors' => config('finance.rmd.divisors'),
            'irmaa' => config("finance.irmaa.tiers.{$status}") ?? config('finance.irmaa.tiers.single'),
            'persons' => $status === 'married_joint' ? 2 : 1,
        ];

        $strategies = ConversionStrategy::query()->onlyOwnedBy($user)->inDefaultOrder()->with('scenario.scenarioFlows')->get();

        // Each projection is worked out once, however many strategies build
        // on it. 0 is the flows as entered.
        $projections = [];

        foreach ($strategies as $strategy) {
            $projections[$strategy->scenario_id ?? 0] ??= $this->scenarios->yearly(
                $this->scenarios->rows($flows, $strategy->scenario?->scenarioFlows ?? collect(), $profile),
            );
        }

        return [
            'world' => $world,
            'strategies' => $strategies,
            'years' => $strategies->mapWithKeys(fn (ConversionStrategy $strategy): array => [$strategy->id => $projections[$strategy->scenario_id ?? 0]])->all(),
        ];
    }

    /**
     * One strategy, year by year.
     *
     * @param  array<string, mixed>  $world  What for() worked out once: the profile, ages, opening balances, contributions and tax tables.
     * @param  array<int, array{year: int, age: int, income: float, expenses: float, taxed: array<string, float>}>  $years  The projection, keyed by year.
     * @param  array{returns: list<float>, inflation: list<float>}|null  $path  One market: a return and an inflation rate (percent) for each year of the plan, in order. Null is the steady market the strategy's own rates describe.
     * @return array{assumptions: array<string, mixed>, rows: list<array<string, float|int|null>>, summary: array<string, float|int|null>}
     */
    public function simulate(ConversionStrategy $strategy, array $world, array $years, ?array $path = null): array
    {
        ['profile' => $profile, 'status' => $status, 'ages' => $ages, 'federal' => $federal, 'tax_on' => $taxOn, 'divisors' => $divisors] = $world;

        $inflationRate = $this->inflationRate($strategy, $world);
        $growthRate = $this->growthRate($strategy, $world);
        $window = $this->window($strategy->kind, $strategy->convert_from_age, $strategy->convert_until_age, $ages);
        $taxations = config('finance.flow_taxations');
        $oldestDivisor = array_key_last($divisors);

        ['deferred' => $traditional, 'free' => $roth, 'taxable' => $taxable] = $world['balances'];

        $rows = [];
        $magi = [];
        $openingMagi = null;
        $shortAtAge = null;

        // Prices now, against today's: 1 this year, then each year's
        // inflation compounded onto the last.
        $index = 1.0;

        for ($age = $ages['now'], $step = 0; $age <= $ages['last']; $age++, $step++) {
            $year = $ages['birth_year'] + $age;
            $inflation = 1 + ($path['inflation'][$step] ?? $inflationRate) / 100;
            $growth = 1 + ($path['returns'][$step] ?? $growthRate) / 100;
            $isRetired = $age >= $ages['retirement'];

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

            $openingMagi ??= ($ordinary + $gains) / $index;

            // The RMD is worked on the balance the year opened with, which is
            // what `$traditional` still holds at this point in the loop.
            $rmd = 0.0;

            if ($age >= $ages['rmd_start'] && $traditional > 0) {
                $rmd = $traditional / $divisors[min($age, $oldestDivisor)];
            }

            $traditional -= $rmd;

            $conversion = $this->conversion($strategy, $window, $age, $traditional, $ordinary + $rmd, $gains, $world, $index);

            // This year's premium was set by the income of two years ago;
            // before the plan has two years behind it, by where it started.
            $irmaaTier = 0;
            $irmaa = 0.0;

            if ($age >= self::MEDICARE_AGE) {
                $irmaaTier = $this->irmaaTier($magi[$year - self::IRMAA_LOOKBACK] ?? $openingMagi, $world['irmaa']);
                $irmaa = $this->irmaaSurcharge($irmaaTier, $world) * $index;
            }

            // Everything from the retirement accounts is ordinary income.
            $taxWith = fn (float $extra): float => $taxOn([...$taxed, 'income_only' => ($taxed['income_only'] ?? 0.0) + $extra], $index)['total'];
            $baseTax = $taxWith(0.0);

            /*
             * The tax the conversion adds, on top of everything else the year
             * was already going to be taxed on, and the part of it taken out
             * of the converted money before that reaches the Roth. The whole
             * conversion is income either way; withholding only changes
             * where the tax is found, and how much arrives.
             */
            $conversionTax = $conversion > 0 ? $taxWith($rmd + $conversion) - $taxWith($rmd) : 0.0;
            $withheld = min($conversion, $this->withheld($strategy, $conversionTax, $index));

            // Negative is a surplus. See the class comment for the two cases.
            // What was withheld has already paid that much of the tax.
            $shortfallAt = fn (float $tax): float => $isRetired
                ? $expenses + $tax + $irmaa - $cashIncome - $rmd - $withheld
                : $tax - $baseTax + $irmaa - $rmd - $withheld;

            /*
             * What has to be found from savings depends on the tax, and the
             * tax depends on how much of it is found from the traditional
             * bucket — a withdrawal there is income too. Settled by going
             * round until the withdrawal stops moving; it converges quickly
             * because each extra dollar withdrawn adds less than a dollar of
             * tax.
             */
            $fromTraditional = 0.0;

            for ($pass = 0; $pass < 25; $pass++) {
                $wanted = min($traditional - $conversion, max(0.0, $shortfallAt($taxWith($rmd + $conversion + $fromTraditional)) - $taxable));

                if (abs($wanted - $fromTraditional) < 0.5) {
                    break;
                }

                $fromTraditional = $wanted;
            }

            $tax = $taxWith($rmd + $conversion + $fromTraditional);
            $shortfall = $shortfallAt($tax);

            $fromTaxable = min($taxable, max(0.0, $shortfall));
            $fromRoth = min($roth + $conversion - $withheld, max(0.0, $shortfall - $fromTaxable - $fromTraditional));

            if ($shortfall - $fromTaxable - $fromTraditional - $fromRoth > 1 && $shortAtAge === null) {
                $shortAtAge = $age;
            }

            $traditional -= $conversion + $fromTraditional;
            $roth += $conversion - $withheld - $fromRoth;
            $taxable += $shortfall < 0 ? -$shortfall : -$fromTaxable;

            if (! $isRetired) {
                $traditional += $world['contributions']['deferred'];
                $roth += $world['contributions']['free'];
                $taxable += $world['contributions']['taxable'];
            }

            $traditional *= $growth;
            $roth *= $growth;
            $taxable *= $growth;

            $bracketIncome = $ordinary + $rmd + $conversion + $fromTraditional;
            $magi[$year] = ($bracketIncome + $gains) / $index;

            // Year-end balances are deflated by the *next* year's index,
            // since that is the price level they will be spent at.
            $endIndex = $index * $inflation;

            $rows[] = [
                'age' => $age,
                'year' => $year,
                'income' => round($cashIncome / $index),
                'expenses' => round($expenses / $index),
                'rmd' => round($rmd / $index),
                'conversion' => round($conversion / $index),
                'withdrawal' => round($fromTraditional / $index),
                // What the tax brackets are read against, with and without
                // the conversion, so a chart can show what the conversion did.
                'bracket_income' => round($bracketIncome / $index),
                'bracket_income_before' => round(($bracketIncome - $conversion) / $index),
                'marginal_rate' => $federal->marginalRate($bracketIncome, $status, $index),
                // What the IRMAA tiers are read against. It sets the premium
                // two years on; `irmaa` is the surcharge paid this year.
                'magi' => round($magi[$year]),
                'magi_before' => round($magi[$year] - $conversion / $index),
                'magi_tier' => $this->irmaaTier($magi[$year], $world['irmaa']),
                'irmaa_tier' => $irmaaTier,
                'irmaa' => round($irmaa / $index),
                'tax' => round($tax / $index),
                // Of that, what the conversion alone added, and how much of
                // it came out of the converted money.
                'conversion_tax' => round($conversionTax / $index),
                'conversion_tax_withheld' => round($withheld / $index),
                'tax_and_irmaa' => round($tax / $index) + round($irmaa / $index),
                'traditional' => round($traditional / $endIndex),
                'roth' => round($roth / $endIndex),
                'taxable' => round($taxable / $endIndex),
                'retirement_balance' => round($traditional / $endIndex) + round($roth / $endIndex),
                'total_balance' => round($traditional / $endIndex) + round($roth / $endIndex) + round($taxable / $endIndex),
            ];

            $index = $endIndex;
        }

        $last = $rows[array_key_last($rows)];
        $lifetimeTax = array_sum(array_column($rows, 'tax'));
        $heirTax = round($this->heirTax($strategy, $last['traditional']));

        return [
            // The settings as they were resolved, blanks filled in.
            'assumptions' => [
                'inflation_rate' => $inflationRate,
                'growth_rate' => $growthRate,
                'scenario_name' => $strategy->scenario?->name,
                'convert_from_age' => $window[0] ?? null,
                'convert_until_age' => $window[1] ?? null,
            ],
            'rows' => $rows,
            'summary' => [
                'converted' => array_sum(array_column($rows, 'conversion')),
                'total_rmd' => array_sum(array_column($rows, 'rmd')),
                'lifetime_tax' => $lifetimeTax,
                'conversion_tax' => array_sum(array_column($rows, 'conversion_tax')),
                'conversion_tax_withheld' => array_sum(array_column($rows, 'conversion_tax_withheld')),
                'irmaa' => array_sum(array_column($rows, 'irmaa')),
                'irmaa_years' => count(array_filter($rows, fn (array $row): bool => $row['irmaa'] > 0)),
                'peak_marginal_rate' => max(array_column($rows, 'marginal_rate')),
                // The tax that outlives the plan: what whoever inherits the
                // traditional balance pays to draw it. Roth and taxable money
                // pass with none.
                'heir_tax' => $heirTax,
                'heir_tax_rate' => $last['traditional'] > 0 ? round($heirTax / $last['traditional'] * 100, 1) : 0.0,
                'tax_with_heirs' => $lifetimeTax + $heirTax,
                'ending_traditional' => $last['traditional'],
                'ending_roth' => $last['roth'],
                'ending_taxable' => $last['taxable'],
                'ending_balance' => $last['traditional'] + $last['roth'] + $last['taxable'],
                'ending_after_heir_tax' => $last['traditional'] + $last['roth'] + $last['taxable'] - $heirTax,
                'short_at_age' => $shortAtAge,
            ],
        ];
    }

    /**
     * The strategy's own inflation rate, then its projection's, then the
     * profile's: the average a Monte Carlo market varies around.
     *
     * @param  array<string, mixed>  $world
     */
    public function inflationRate(ConversionStrategy $strategy, array $world): float
    {
        return $strategy->inflation_rate ?? $strategy->scenario?->bracket_inflation_rate ?? $world['profile']->inflation_rate;
    }

    /**
     * The strategy's own growth rate, or the fleet's.
     *
     * @param  array<string, mixed>  $world
     */
    public function growthRate(ConversionStrategy $strategy, array $world): float
    {
        return $strategy->growth_rate ?? $world['growth_rate'];
    }

    /**
     * How much of a conversion's tax is taken out of the converted money,
     * by the strategy's `tax_payment`. The rest is found from outside it,
     * like any other tax.
     *
     * A flat amount is in today's dollars, so it is raised to the year's.
     * Money withheld before 59½ would also owe a 10% penalty, which is not
     * modelled.
     */
    private function withheld(ConversionStrategy $strategy, float $conversionTax, float $index): float
    {
        return match ($strategy->tax_payment) {
            'conversion' => $conversionTax,
            'percent' => $conversionTax * (1 - min(100.0, $strategy->tax_outside_amount ?? 100.0) / 100),
            'flat' => max(0.0, $conversionTax - ($strategy->tax_outside_amount ?? 0.0) * $index),
            default => 0.0,
        };
    }

    /**
     * The first and last age a kind of strategy converts at, with blanks
     * filled in and nothing before today. Null for a strategy that does not
     * convert.
     *
     * @param  array<string, int>  $ages
     * @return array{0: int, 1: int}|null
     */
    private function window(string $kind, ?int $from, ?int $until, array $ages): ?array
    {
        if ($kind === 'lump') {
            $at = max($ages['now'], $from ?? self::WINDOW[0]);

            return [$at, $at];
        }

        if ($kind === 'even') {
            $first = max($ages['now'], $from ?? self::WINDOW[0]);

            return [$first, max($first, $until ?? self::WINDOW[1])];
        }

        if (config("finance.conversion_strategies.{$kind}.fills")) {
            $first = max($ages['now'], $from ?? $ages['now']);

            return [$first, max($first, $until ?? $ages['last'])];
        }

        return null;
    }

    /**
     * What the strategy converts this year, from the traditional balance left
     * after the RMD.
     *
     * @param  array{0: int, 1: int}|null  $window
     * @param  float  $ordinaryIncome  The year's ordinary income before any conversion, the RMD included.
     * @param  float  $gains  The year's capital gains, which count towards IRMAA but not towards the ordinary brackets.
     * @param  array<string, mixed>  $world
     */
    private function conversion(ConversionStrategy $strategy, ?array $window, int $age, float $traditional, float $ordinaryIncome, float $gains, array $world, float $index): float
    {
        if ($window === null || $age < $window[0] || $age > $window[1] || $traditional <= 0) {
            return 0.0;
        }

        if ($strategy->kind === 'lump') {
            return $traditional;
        }

        // Equal parts of what is left: a fifth with five years to go, then a
        // quarter, and all of it in the last.
        if ($strategy->kind === 'even') {
            return $traditional / ($window[1] - $age + 1);
        }

        // The bracket to fill: named outright, or read off this year's
        // income. The open top bracket has no top, so nothing is converted.
        $rate = $strategy->fill_rate ?? $world['federal']->marginalRate($ordinaryIncome, $world['status'], $index);
        $top = $world['federal']->grossCeiling($rate, $world['status'], $index);

        // Room left in the bracket; none to speak of in the top one.
        $room = $top === null ? INF : $top - $ordinaryIncome;

        /*
         * The IRMAA-aware kind also stops at the top of the IRMAA tier the
         * year's income is already in. Only once the income can cost
         * anything: a premium is set by the income of two years before, so
         * nothing earned before 63 ever reaches one. Income sitting in the
         * top tier has no ceiling to stay under and is left to the bracket.
         */
        if ($strategy->kind === 'fill_bracket_irmaa' && $age >= self::MEDICARE_AGE - self::IRMAA_LOOKBACK) {
            $income = $ordinaryIncome + $gains;
            $ceiling = $world['irmaa'][$this->irmaaTier($income / $index, $world['irmaa'])][0];

            if ($ceiling !== null) {
                $room = min($room, $ceiling * $index - $income);
            }
        }

        if ($room === INF) {
            return 0.0;
        }

        return max(0.0, min($traditional, $room));
    }

    /**
     * The IRMAA tier an income lands in: 0 is the standard premium.
     *
     * @param  float  $magi  In today's dollars, as the tiers are.
     * @param  list<array{0: int|float|null, 1: int|float, 2: int|float}>  $tiers
     */
    private function irmaaTier(float $magi, array $tiers): int
    {
        foreach ($tiers as $tier => [$ceiling]) {
            if ($ceiling === null || $magi <= $ceiling) {
                return $tier;
            }
        }

        return array_key_last($tiers);
    }

    /**
     * What a tier costs the household in a year over the standard premium, in
     * today's dollars: the extra Part B and the Part D surcharge, each month,
     * for each person.
     *
     * @param  array<string, mixed>  $world
     */
    private function irmaaSurcharge(int $tier, array $world): float
    {
        [, $partB, $partD] = $world['irmaa'][$tier];

        return ($partB - $world['irmaa'][0][1] + $partD) * 12 * $world['persons'];
    }

    /**
     * The income tax whoever inherits the traditional balance pays on it, in
     * today's dollars. See the class comment for how it is drawn.
     */
    private function heirTax(ConversionStrategy $strategy, float $traditional): float
    {
        if ($strategy->heir_is_charity || $traditional <= 0) {
            return 0.0;
        }

        $income = $strategy->heir_income ?? 0.0;

        // The plain calculator, not the profile's: the heir has the built-in
        // deduction, not the deduction of the person they inherit from.
        return self::INHERITED_YEARS * ($this->tax->tax($income + $traditional / self::INHERITED_YEARS, 'single') - $this->tax->tax($income, 'single'));
    }

    /**
     * The top of each federal bracket as a gross income — the deduction
     * included — in today's dollars: the lines a year's income is charted
     * against. The first is the deduction itself, the top of a 0% bracket.
     *
     * @param  array<string, mixed>  $world
     * @return list<array{label: string, value: float}>
     */
    private function bracketLines(array $world): array
    {
        $lines = [['label' => '0%', 'value' => $world['federal']->deduction($world['status'])]];

        foreach ($world['federal']->brackets($world['status']) as [$rate, $ceiling]) {
            if ($ceiling !== null) {
                $lines[] = ['label' => "{$rate}%", 'value' => $world['federal']->grossCeiling((float) $rate, $world['status'])];
            }
        }

        return $lines;
    }

    /**
     * The top of each IRMAA tier, in today's dollars, with what the tier
     * above it costs a year.
     *
     * @param  array<string, mixed>  $world
     * @return list<array{label: string, value: float, surcharge_above: float}>
     */
    private function irmaaLines(array $world): array
    {
        $lines = [];

        foreach ($world['irmaa'] as $tier => [$ceiling]) {
            if ($ceiling !== null) {
                $lines[] = [
                    'label' => $tier === 0 ? 'No surcharge' : "Tier {$tier}",
                    'value' => (float) $ceiling,
                    'surcharge_above' => round($this->irmaaSurcharge($tier + 1, $world)),
                ];
            }
        }

        return $lines;
    }
}
