<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\User;
use Closure;

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
 *    taxable (the investable accounts that are neither), each growing at
 *    what its own holdings expect unless the strategy sets one rate for all.
 *    A Monte Carlo market moves all three by the same points each year.
 *  - Each year's income and expenses come from the projection
 *    (ScenarioBoard), before tax. The RMD, any conversion and any traditional
 *    withdrawal are added as ordinary income, and the year is taxed whole by
 *    TaxCalculator::flowTaxFor() on tables raised by the strategy's inflation
 *    rate.
 *  - A conversion's tax paid "from outside" is paid from the year's own
 *    surplus — income less expenses, other tax, IRMAA and, while working,
 *    the year's contributions — and the conversion is capped at what that surplus can pay the tax on, so savings
 *    are never drawn down for it. A strategy may instead have some or all of
 *    the tax withheld from the converted money, which is not capped: then
 *    that much less reaches the Roth, and any part paid from outside is
 *    found like any other cost.
 *  - Every year, working or retired, expenses plus tax plus IRMAA — and,
 *    while working, the fleet's monthly contributions, which go into their
 *    accounts — are met from income and the RMD first, then the taxable
 *    bucket, then traditional, then Roth, and a surplus is saved to the
 *    taxable bucket. So the projection has to list every expense: whatever
 *    it leaves out is counted as saved.
 *  - Money taken from traditional before 59½ — a withdrawal, or conversion
 *    tax withheld from the converted money — owes the 10% early-withdrawal
 *    penalty, counted until the year of turning 60.
 *  - IRMAA is charged from 65 (from the birthday month in that year), per
 *    person (two on a joint return), on the income of two years before
 *    against that year's tiers, which rise with inflation. Income
 *    for that purpose is taxed income plus the RMD, conversion and withdrawal:
 *    the untaxed part of Social Security is not added back. A strategy that
 *    stays inside an IRMAA tier does so for the conversion alone: a traditional
 *    withdrawal made later that year to cover spending can still cross it.
 *  - Heirs are taken to draw an inherited traditional balance in ten equal
 *    parts on top of their own income, as a single filer on the federal
 *    tables. No growth inside those ten years, no state tax. A charity pays
 *    nothing.
 *  - Growth in the taxable bucket is not taxed along the way. The gain left
 *    in it at the end is taxed once, with the heirs' tax, as an estimate of
 *    what was left unpaid (`leftover_tax`). The Roth five-year rules are
 *    ignored.
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

    /**
     * How far short of an IRMAA cliff the IRMAA-aware strategy stops, in
     * today's dollars. Filling to the dollar left a year sitting exactly on
     * the line, where rounding two years later could tip it into the tier
     * above — and a tier is a cliff, so a dollar over costs the whole step.
     */
    private const IRMAA_MARGIN = 100;

    /** The years an heir has to empty an inherited account. */
    private const INHERITED_YEARS = 10;

    /** The usual pre-RMD window, for a strategy that does not name its own. */
    private const WINDOW = [65, 72];

    /** The first age, counted as the year turned, free of the early-withdrawal penalty (59½). */
    private const PENALTY_FREE_AGE = 60;

    /** The additional tax on an early withdrawal from a traditional account. */
    private const EARLY_PENALTY = 0.10;

    /** No year loses more than this, however unlucky a market. */
    private const WORST_RETURN = -95.0;

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
            'default_conversion_amount' => (float) config('finance.defaults.conversion_amount'),
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
     * The household as every retirement tool reads it: the profile, the ages
     * that matter, the three buckets with what each holds, takes in and grows
     * at, and the tax tables.
     *
     * Public for WithdrawalBoard, which draws the same buckets down.
     *
     * @return array<string, mixed>
     */
    public function world(User $user): array
    {
        $profile = Profile::for($user);
        // Leaves, so the accounts inside a compound retirement account are
        // each counted, under the tax treatment they inherit from it.
        $investable = $this->fleet->leaves($this->fleet->holdings($user))->filter->is_investable;
        $invested = (float) $investable->sum->value;
        $federal = $this->tax->forProfile($profile);
        $status = $profile->filing_status;

        $bucket = fn (string $treatment): Collection => $investable->where('tax_treatment', $treatment);
        $growthRate = $invested > 0
            ? round($investable->sum(fn (Holding $holding): float => $holding->value * $holding->expected_rate) / $invested, 1)
            : (float) config('finance.defaults.market_return');

        // Each bucket grows at what its own holdings expect, so money moved
        // from traditional to Roth grows as Roth money does. An empty bucket
        // takes the fleet's rate, for whatever a conversion puts in it.
        $bucketRate = function (string $treatment) use ($bucket, $growthRate): float {
            $holdings = $bucket($treatment);
            $value = (float) $holdings->sum->value;

            if ($value > 0) {
                return round($holdings->sum(fn (Holding $holding): float => $holding->value * $holding->expected_rate) / $value, 2);
            }

            return $growthRate;
        };

        return [
            'profile' => $profile,
            'status' => $status,
            'ages' => [
                'now' => $profile->age,
                'last' => max($profile->age, $profile->life_expectancy),
                'retirement' => $profile->retirement_age,
                'rmd_start' => $profile->rmd_start_age,
                'birth_year' => $profile->birth_year,
                'birth_month' => $profile->birth_date?->month,
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
            'growth_rate' => $growthRate,
            'growth_rates' => [
                'deferred' => $bucketRate('deferred'),
                'free' => $bucketRate('free'),
                'taxable' => $bucketRate('taxable'),
            ],
            'federal' => $federal,
            'tax_on' => $this->tax->flowTaxFor($profile),
            'divisors' => config('finance.rmd.divisors'),
            'irmaa' => config("finance.irmaa.tiers.{$status}") ?? config('finance.irmaa.tiers.single'),
            'persons' => $status === 'married_joint' ? 2 : 1,
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
        $world = $this->world($user);
        $profile = $world['profile'];
        $flows = $this->fleet->flows($user);

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
     * @param  array{shocks: list<float>, inflation: list<float>}|null  $path  One market: for each year of the plan, in order, how many points the return lands above or below each bucket's average, and the inflation rate (percent). Null is the steady market the strategy's own rates describe.
     * @return array{assumptions: array<string, mixed>, rows: list<array<string, float|int|null>>, summary: array<string, float|int|null>}
     */
    public function simulate(ConversionStrategy $strategy, array $world, array $years, ?array $path = null): array
    {
        ['status' => $status, 'ages' => $ages, 'federal' => $federal, 'tax_on' => $taxOn, 'divisors' => $divisors] = $world;

        $inflationRate = $this->inflationRate($strategy, $world);
        $growthRates = $this->bucketRates($strategy, $world);
        $window = $this->window($strategy->kind, $strategy->convert_from_age, $strategy->convert_until_age, $ages);
        $taxations = config('finance.flow_taxations');
        $oldestDivisor = array_key_last($divisors);
        $contributions = $world['contributions'];

        ['deferred' => $traditional, 'free' => $roth, 'taxable' => $taxable] = $world['balances'];

        // What was paid into the taxable bucket, as against what it has
        // grown to: the difference is the gain a sale would be taxed on.
        // The opening balance is taken as all basis, since the fleet does
        // not record what was paid for it.
        $basis = $taxable;

        $rows = [];
        $magi = [];
        $openingMagi = null;
        $shortAtAge = null;

        // Prices now, against today's: 1 this year, then each year's
        // inflation compounded onto the last. `$steadyIndex` is where they
        // would be had every year's inflation been the average.
        $index = 1.0;
        $steadyIndex = 1.0;

        for ($age = $ages['now'], $step = 0; $age <= $ages['last']; $age++, $step++) {
            $year = $ages['birth_year'] + $age;
            $inflation = 1 + ($path['inflation'][$step] ?? $inflationRate) / 100;
            $shock = $path['shocks'][$step] ?? 0.0;
            $growth = array_map(fn (float $rate): float => 1 + max(self::WORST_RETURN, $rate + $shock) / 100, $growthRates);
            $isRetired = $age >= $ages['retirement'];
            $isPenalised = $age < self::PENALTY_FREE_AGE;

            ['income' => $cashIncome, 'expenses' => $expenses, 'taxed' => $taxed] = $years[$year] ?? ['income' => 0.0, 'expenses' => 0.0, 'taxed' => []];

            // The projection's expenses rise at the average inflation; a
            // market whose prices ran ahead of that, or behind it, moves
            // them with it. Income is left as projected — a pension that
            // keeps pace is the exception — so a run of high inflation costs
            // something, as it would.
            $expenses *= $index / $steadyIndex;

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

            /*
             * This year's premium was set by the income of two years ago,
             * against this year's tiers — they rise with prices, so an income
             * is deflated by today's index, not by the one it was earned at.
             * Before the plan has two years behind it, by where it started.
             * The year Medicare begins is charged from the birthday month.
             */
            $irmaaTier = 0;
            $irmaa = 0.0;

            if ($age >= self::MEDICARE_AGE) {
                $lookback = isset($magi[$year - self::IRMAA_LOOKBACK]) ? $magi[$year - self::IRMAA_LOOKBACK] / $index : $openingMagi;
                $months = $age === self::MEDICARE_AGE && $ages['birth_month'] !== null ? 13 - $ages['birth_month'] : 12;
                $irmaaTier = $this->irmaaTier($lookback, $world['irmaa']);
                $irmaa = $this->irmaaSurcharge($irmaaTier, $world) * $index * $months / 12;
            }

            // The tiers this year's income will be read against, two years
            // on, as far as the strategy's inflation rate can tell.
            $irmaaIndex = $index * (1 + $inflationRate / 100) ** self::IRMAA_LOOKBACK;

            // Everything from the retirement accounts is ordinary income.
            $taxWith = fn (float $extra): float => $taxOn([...$taxed, 'income_only' => ($taxed['income_only'] ?? 0.0) + $extra], $index, $age)['total'];

            // The 10% additional tax on money taken out of a traditional
            // account before 59½ — counted from the year of turning 60, to
            // be safe. RMDs and conversions themselves are exempt.
            $penaltyOn = fn (float $amount): float => $isPenalised ? $amount * self::EARLY_PENALTY : 0.0;

            // What a working year's income pays into the accounts, before
            // anything is spare.
            $saving = $isRetired ? 0.0 : array_sum($contributions);

            /*
             * The year with a given withdrawal from traditional: what is
             * converted, the tax the conversion adds and how much of it is
             * withheld from the converted money, the year's tax, and what
             * still has to be found from savings (negative is a surplus, which
             * is saved).
             *
             * The conversion is worked out *after* the withdrawal, which is
             * income too: a strategy filling a bracket or an IRMAA tier has
             * to leave room for it, or the money drawn to pay the
             * conversion's own tax would push the year over the very line the
             * conversion stopped at.
             */
            $evaluate = function (float $fromTraditional, bool $converting) use ($strategy, $window, $age, $traditional, $ordinary, $rmd, $gains, $world, $index, $irmaaIndex, $taxWith, $penaltyOn, $saving, $expenses, $irmaa, $cashIncome): array {
                $conversion = $converting
                    ? $this->conversion($strategy, $window, $age, $traditional - $fromTraditional, $ordinary + $rmd + $fromTraditional, $gains, $world, $index, $irmaaIndex)
                    : 0.0;

                /*
                 * A conversion whose tax is paid from outside it is paid from
                 * the year's own surplus — what is left of the year's income
                 * once its expenses, its other tax, any IRMAA and, while
                 * working, the year's contributions are met — and so is no
                 * bigger than that surplus can pay the tax on. Savings are
                 * never drawn down to pay for a conversion, and a year with
                 * nothing to spare converts nothing.
                 */
                $paysFromSurplus = $strategy->tax_payment === 'outside';

                if ($conversion > 0 && $paysFromSurplus) {
                    $spare = $cashIncome + $rmd + $fromTraditional - $expenses - $taxWith($rmd + $fromTraditional) - $penaltyOn($fromTraditional) - $irmaa - $saving;
                    $conversion = $this->affordable($conversion, $spare, fn (float $amount): float => $taxWith($rmd + $fromTraditional + $amount) - $taxWith($rmd + $fromTraditional));
                }

                // The tax the conversion adds on top of everything else the
                // year owes, and the part taken out of the converted money
                // before it reaches the Roth. The whole conversion is income
                // either way; withholding only changes where the tax is found.
                $conversionTax = $conversion > 0 ? $taxWith($rmd + $fromTraditional + $conversion) - $taxWith($rmd + $fromTraditional) : 0.0;
                $withheld = min($conversion, $this->withheld($strategy, $conversionTax, $index));

                // Withheld money never reaches the Roth, so before 59½ it is
                // an early withdrawal like any other.
                $penalty = $penaltyOn($fromTraditional + $withheld);
                $tax = $taxWith($rmd + $conversion + $fromTraditional) + $penalty;

                // What was withheld has already paid that much of the tax.
                $shortfall = $expenses + $tax + $irmaa + $saving - $cashIncome - $rmd - $withheld;

                return compact('conversion', 'conversionTax', 'withheld', 'penalty', 'tax', 'shortfall');
            };

            /*
             * What has to be found from savings depends on the tax, and the
             * tax depends on how much of it is found from the traditional
             * bucket. Settled by going round until the withdrawal stops
             * moving; it converges quickly because each extra dollar withdrawn
             * adds less than a dollar of tax.
             */
            $settle = function (bool $converting) use ($evaluate, $traditional, $taxable): array {
                $fromTraditional = 0.0;

                for ($pass = 0; $pass < 25; $pass++) {
                    $year = $evaluate($fromTraditional, $converting);
                    $wanted = min($traditional - $year['conversion'], max(0.0, $year['shortfall'] - $taxable));

                    if (abs($wanted - $fromTraditional) < 0.5) {
                        break;
                    }

                    $fromTraditional = $wanted;
                }

                return [$fromTraditional, $evaluate($fromTraditional, $converting)];
            };

            [$fromTraditional, ['conversion' => $conversion, 'conversionTax' => $conversionTax, 'withheld' => $withheld, 'penalty' => $penalty, 'tax' => $tax, 'shortfall' => $shortfall]] = $settle(true);

            // The same year had it not converted, for the charts' dashed line:
            // without the conversion there may be less to withdraw, too.
            $withdrawalWithout = $conversion > 0 ? $settle(false)[0] : $fromTraditional;

            $fromTaxable = min($taxable, max(0.0, $shortfall));
            $fromRoth = min($roth + $conversion - $withheld, max(0.0, $shortfall - $fromTaxable - $fromTraditional));

            if ($shortfall - $fromTaxable - $fromTraditional - $fromRoth > 1 && $shortAtAge === null) {
                $shortAtAge = $age;
            }

            $traditional -= $conversion + $fromTraditional;
            $roth += $conversion - $withheld - $fromRoth;
            // Money taken out takes its share of the basis with it; money
            // saved or contributed is basis in full.
            if ($shortfall < 0) {
                $basis -= $shortfall;
            } elseif ($taxable > 0) {
                $basis *= 1 - $fromTaxable / $taxable;
            }

            $taxable += $shortfall < 0 ? -$shortfall : -$fromTaxable;

            if (! $isRetired) {
                $traditional += $contributions['deferred'];
                $roth += $contributions['free'];
                $taxable += $contributions['taxable'];
                $basis += $contributions['taxable'];
            }

            $traditional *= $growth['deferred'];
            $roth *= $growth['free'];
            $taxable *= $growth['taxable'];

            $bracketIncome = $ordinary + $rmd + $conversion + $fromTraditional;

            // The owner's income in the plan's last year, apart from any
            // conversion, in today's dollars: what the gains left in the
            // taxable bucket are taken to be realised on top of.
            $finalIncome = ['ordinary' => ($ordinary + $rmd + $fromTraditional) / $index, 'gains' => $gains / $index, 'age' => $age];
            $incomeBefore = $ordinary + $rmd + $withdrawalWithout;
            $deduction = $federal->deduction($status, $index, $age);
            $magi[$year] = $bracketIncome + $gains;

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
                // Ordinary income before the deduction, and taxable income
                // after it — what the brackets are read against — with and
                // without the conversion, so a chart can show what the
                // conversion did.
                'bracket_income' => round($bracketIncome / $index),
                'taxable_income' => round(max(0.0, $bracketIncome - $deduction) / $index),
                'taxable_income_before' => round(max(0.0, $incomeBefore - $deduction) / $index),
                // The bracket the year's last dollar is taxed in. Asked of a
                // dollar less, because marginalRate() gives the rate on the
                // *next* dollar, and a year filled exactly to the top of the
                // 35% bracket would otherwise read as 37%.
                'marginal_rate' => $federal->marginalRate(max(0.0, $bracketIncome - 1), $status, $index, $age),
                // What the IRMAA tiers are read against, in the prices of the
                // year whose premium it sets, two years on, so it can be set
                // against today's tiers. `irmaa` is the surcharge paid this
                // year.
                'magi' => round($magi[$year] / $irmaaIndex),
                'magi_before' => round(($incomeBefore + $gains) / $irmaaIndex),
                'magi_tier' => $this->irmaaTier($magi[$year] / $irmaaIndex, $world['irmaa']),
                'irmaa_tier' => $irmaaTier,
                'irmaa' => round($irmaa / $index),
                'tax' => round($tax / $index),
                // Of that, what the conversion alone added, how much of it
                // came out of the converted money, and the early-withdrawal
                // penalty, if any.
                'conversion_tax' => round($conversionTax / $index),
                'conversion_tax_withheld' => round($withheld / $index),
                'penalty' => round($penalty / $index),
                'tax_and_irmaa' => round($tax / $index) + round($irmaa / $index),
                'traditional' => round($traditional / $endIndex),
                'roth' => round($roth / $endIndex),
                'taxable' => round($taxable / $endIndex),
                'retirement_balance' => round($traditional / $endIndex) + round($roth / $endIndex),
                'total_balance' => round($traditional / $endIndex) + round($roth / $endIndex) + round($taxable / $endIndex),
            ];

            $index = $endIndex;
            $steadyIndex *= 1 + $inflationRate / 100;
        }

        $last = $rows[array_key_last($rows)];
        $lifetimeTax = array_sum(array_column($rows, 'tax'));
        $lifetimeIrmaa = array_sum(array_column($rows, 'irmaa'));
        $heirTax = round($this->heirTax($strategy, $last['traditional']));
        // The growth still sitting untaxed in the taxable bucket, in
        // today's dollars. A loss is no gain, not a negative one.
        $taxableGain = round(max(0.0, $taxable - $basis) / $index);
        $gainsTax = round($this->gainsTax($world, $taxableGain, $finalIncome));
        $endingBalance = $last['traditional'] + $last['roth'] + $last['taxable'];

        return [
            // The settings as they were resolved, blanks filled in.
            'assumptions' => [
                'inflation_rate' => $inflationRate,
                'growth_rate' => $this->growthRate($strategy, $world),
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
                'penalties' => array_sum(array_column($rows, 'penalty')),
                'irmaa' => $lifetimeIrmaa,
                'irmaa_years' => count(array_filter($rows, fn (array $row): bool => $row['irmaa'] > 0)),
                'peak_marginal_rate' => max(array_column($rows, 'marginal_rate')),
                // The tax that outlives the plan: what whoever inherits the
                // traditional balance pays to draw it. Roth and taxable money
                // pass with none.
                'heir_tax' => $heirTax,
                'heir_tax_rate' => $last['traditional'] > 0 ? round($heirTax / $last['traditional'] * 100, 1) : 0.0,
                // Everything the government takes, now and later: IRMAA is
                // in it, since a conversion that raises it costs as surely
                // as one that raises the tax.
                'tax_with_heirs' => $lifetimeTax + $lifetimeIrmaa + $heirTax,
                'ending_traditional' => $last['traditional'],
                'ending_roth' => $last['roth'],
                'ending_taxable' => $last['taxable'],
                'ending_balance' => $last['traditional'] + $last['roth'] + $last['taxable'],
                'ending_after_heir_tax' => $last['traditional'] + $last['roth'] + $last['taxable'] - $heirTax,
                // The tax still owed on what is left: the heirs' income tax
                // on the traditional balance, and the owner's capital gains
                // tax on the growth in the taxable bucket, which the plan
                // never charged along the way. What is left after both is what can
                // actually be inherited.
                'taxable_gain' => $taxableGain,
                'gains_tax' => $gainsTax,
                'leftover_tax' => $heirTax + $gainsTax,
                'inheritable' => $endingBalance - $heirTax - $gainsTax,
                'short_at_age' => $shortAtAge,
            ],
        ];
    }

    /**
     * The most of `$wanted` whose tax a budget can pay: all of it if the
     * budget covers that, nothing if there is no budget, and otherwise the
     * amount whose tax comes to the budget, to within a dollar.
     *
     * Tax rises with income in straight-line pieces, a bracket at a time, so
     * the search walks along the line between the last amount found
     * affordable and the last found too dear (regula falsi, with the Illinois
     * adjustment so neither end sticks). It lands within a few steps.
     *
     * @param  Closure(float): float  $taxOf  The tax a conversion of an amount adds.
     */
    private function affordable(float $wanted, float $budget, Closure $taxOf): float
    {
        if ($budget <= 0) {
            return 0.0;
        }

        $overAtWanted = $taxOf($wanted) - $budget;

        if ($overAtWanted <= 0) {
            return $wanted;
        }

        [$low, $overAtLow, $high, $overAtHigh] = [0.0, -$budget, $wanted, $overAtWanted];
        $lastMoved = null;

        for ($step = 0; $step < 40 && $high - $low > 1; $step++) {
            $guess = $high - $overAtHigh * ($high - $low) / ($overAtHigh - $overAtLow);
            $over = $taxOf($guess) - $budget;

            if (abs($over) < 0.5) {
                return $guess;
            }

            if ($over > 0) {
                [$high, $overAtHigh] = [$guess, $over];

                if ($lastMoved === 'high') {
                    $overAtLow /= 2;
                }

                $lastMoved = 'high';
            } else {
                [$low, $overAtLow] = [$guess, $over];

                if ($lastMoved === 'low') {
                    $overAtHigh /= 2;
                }

                $lastMoved = 'low';
            }
        }

        return $low;
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
     * The strategy's own growth rate, or the fleet's: the one figure the page
     * shows for it.
     *
     * @param  array<string, mixed>  $world
     */
    public function growthRate(ConversionStrategy $strategy, array $world): float
    {
        return $strategy->growth_rate ?? $world['growth_rate'];
    }

    /**
     * What each bucket grows at: the strategy's own rate for all three when
     * it has one, otherwise each bucket's own holdings'.
     *
     * @param  array<string, mixed>  $world
     * @return array{deferred: float, free: float, taxable: float}
     */
    private function bucketRates(ConversionStrategy $strategy, array $world): array
    {
        if ($strategy->growth_rate !== null) {
            return array_fill_keys(['deferred', 'free', 'taxable'], $strategy->growth_rate);
        }

        return $world['growth_rates'];
    }

    /**
     * How much of a conversion's tax is taken out of the converted money,
     * by the strategy's `tax_payment`. The rest is found from outside it,
     * like any other tax.
     *
     * A flat amount is in today's dollars, so it is raised to the year's.
     * Money withheld before 59½ also owes the 10% penalty, which simulate()
     * adds to the year's tax.
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

        if ($kind === 'even' || $kind === 'fixed') {
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
     * @param  float  $irmaaIndex  The price level of the year whose premium this year's income sets.
     */
    private function conversion(ConversionStrategy $strategy, ?array $window, int $age, float $traditional, float $ordinaryIncome, float $gains, array $world, float $index, float $irmaaIndex): float
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

        // The same amount each year, in today's dollars, until there is less
        // than that left, and then the rest.
        if ($strategy->kind === 'fixed') {
            return min($traditional, ($strategy->conversion_amount ?? 0.0) * $index);
        }

        // The bracket to fill: named outright, or read off this year's
        // income. The open top bracket has no top, so nothing is converted.
        $rate = $strategy->fill_rate ?? $world['federal']->marginalRate($ordinaryIncome, $world['status'], $index, $age);
        $top = $world['federal']->grossCeiling($rate, $world['status'], $index, $age);

        // Room left in the bracket; none to speak of in the top one.
        $room = $top === null ? INF : $top - $ordinaryIncome;

        /*
         * The IRMAA-aware kind also stops at the top of the IRMAA tier the
         * year's income is already in. Only once the income can cost
         * anything: a premium is set by the income of two years before, so
         * nothing earned before 63 ever reaches one. Income sitting in the
         * top tier has no ceiling to stay under and is left to the bracket.
         * The tiers are the ones of the premium year, two years on, and the
         * conversion stops IRMAA_MARGIN short of the line. Income already
         * inside that margin converts nothing.
         */
        if ($strategy->kind === 'fill_bracket_irmaa' && $age >= self::MEDICARE_AGE - self::IRMAA_LOOKBACK) {
            $income = $ordinaryIncome + $gains;
            $ceiling = $world['irmaa'][$this->irmaaTier($income / $irmaaIndex, $world['irmaa'])][0];

            if ($ceiling !== null) {
                $room = min($room, ($ceiling - self::IRMAA_MARGIN) * $irmaaIndex - $income);
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
    public function irmaaTier(float $magi, array $tiers): int
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
    public function irmaaSurcharge(int $tier, array $world): float
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
     * The long-term capital gains tax on the growth left in the taxable
     * bucket, in today's dollars, as the plan's owner would pay it.
     *
     * Realised in ten equal parts, on the owner's own filing status,
     * deduction and capital gains brackets, stacked on top of the income
     * they have in the last year of the plan (conversions apart) and any
     * gains already in it. Federal only, like the heirs' tax beside it.
     *
     * It is the owner's tax, not an heir's, on purpose (the user's call,
     * 2026-10-03): the figure stands in for the tax on dividends and sales
     * the plan never charges along the way, and for what the owner would pay
     * to draw on the money in an emergency. So the stepped-up basis an heir
     * would get is ignored, and who inherits makes no difference to it.
     * Don't "correct" it to zero.
     *
     * @param  array<string, mixed>  $world
     * @param  array{ordinary: float, gains: float, age: int}  $income  The owner's final-year income.
     */
    private function gainsTax(array $world, float $gain, array $income): float
    {
        if ($gain <= 0) {
            return 0.0;
        }

        $brackets = $this->tax->capitalGainsBrackets($world['profile']);
        $deduction = $world['federal']->deduction($world['status'], 1.0, $income['age']);

        // Gains fill whatever of the deduction ordinary income left unused,
        // then sit on top of it in the brackets.
        $taxableOrdinary = max(0.0, $income['ordinary'] - $deduction);
        $unusedDeduction = max(0.0, $deduction - $income['ordinary']);
        $before = $taxableOrdinary + max(0.0, $income['gains'] - $unusedDeduction);
        $after = $taxableOrdinary + max(0.0, $income['gains'] + $gain / self::INHERITED_YEARS - $unusedDeduction);

        return self::INHERITED_YEARS * ($this->tax->progressive($after, $brackets) - $this->tax->progressive($before, $brackets));
    }

    /**
     * The top of each federal bracket as a taxable income, in today's
     * dollars: the lines a year's taxable income is charted against. Taxable
     * income rather than gross, because the deduction grows at 65 and a
     * gross line would move with it.
     *
     * `label` is the bracket the line is the top of; `above` is the one
     * that begins there, which is what a chart should print on the line —
     * a year sitting over a line named for the bracket beneath it reads as
     * being in that bracket, one too low.
     *
     * @param  array<string, mixed>  $world
     * @return list<array{label: string, above: string, value: float}>
     */
    private function bracketLines(array $world): array
    {
        $lines = [];
        $brackets = $world['federal']->brackets($world['status']);

        foreach ($brackets as $position => [$rate, $ceiling]) {
            if ($ceiling !== null) {
                $lines[] = ['label' => "{$rate}%", 'above' => isset($brackets[$position + 1]) ? "{$brackets[$position + 1][0]}%" : '', 'value' => (float) $ceiling];
            }
        }

        return $lines;
    }

    /**
     * The top of each IRMAA tier, in today's dollars, with what the tier
     * above it costs a year.
     *
     * @param  array<string, mixed>  $world
     * @return list<array{label: string, above: string, value: float, surcharge_above: float}>
     */
    private function irmaaLines(array $world): array
    {
        $lines = [];

        foreach ($world['irmaa'] as $tier => [$ceiling]) {
            if ($ceiling !== null) {
                $lines[] = [
                    'label' => $tier === 0 ? 'No surcharge' : "Tier {$tier}",
                    // The tier that begins at this line, as bracketLines().
                    'above' => 'Tier '.($tier + 1),
                    'value' => (float) $ceiling,
                    'surcharge_above' => round($this->irmaaSurcharge($tier + 1, $world)),
                ];
            }
        }

        return $lines;
    }
}
