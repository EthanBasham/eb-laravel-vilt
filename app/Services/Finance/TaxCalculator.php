<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use Closure;

/**
 * Income tax on ordinary income: federal from the tables in config/finance.php,
 * state and local from the profile's own.
 *
 * A planning estimate, not a return: it knows the standard deduction and the
 * seven brackets and little else. No credits, no NIIT, no IRMAA. State and
 * local tax are added from the brackets a profile carries — see
 * stateAndLocalTax() — and flowTaxFor() adds the two things a household's
 * incomes need that one ordinary-income figure does not: payroll tax and the
 * long-term capital gains brackets.
 *
 * A profile may carry its own standard deduction. The methods that are given
 * a profile use it; the ones given only a filing status use the built-in
 * figure unless the instance came from forProfile().
 *
 * The methods that take an `$age` add the additional standard deduction for
 * the aged from 65, on top of whichever deduction that is. On a joint return
 * it is taken for both spouses, as though they were the same age.
 *
 * `$index` scales every threshold and the deduction together, which is how a
 * future year is modelled: the IRS indexes the tables to inflation each year,
 * so a projection that held them still would drift everyone into higher
 * brackets on nothing but inflated dollars.
 */
class TaxCalculator
{
    /** @var array<string, array{deduction: float, brackets: list<array{0: int|float, 1: int|float|null}>}> */
    private array $tables = [];

    /** A profile's own standard deduction, in place of the table's. */
    private ?float $deduction = null;

    /**
     * This calculator with the profile's own standard deduction, when it has
     * one, in place of the built-in figure — for every method that is only
     * told a filing status.
     */
    public function forProfile(Profile $profile): static
    {
        $calculator = clone $this;
        $calculator->tables = [];
        $calculator->deduction = $profile->standard_deduction;

        return $calculator;
    }

    public function tax(float $grossIncome, string $status, float $index = 1.0, ?int $age = null): float
    {
        $taxable = max(0.0, $grossIncome - $this->deduction($status, $index, $age));

        return $this->progressive($taxable, $this->brackets($status), $index);
    }

    /**
     * State plus local income tax, from the brackets saved on the profile.
     *
     * Each is the same arithmetic as the federal tax with its own deduction
     * and table, applied to the same income. That is cruder than it sounds:
     * states define taxable income their own way — New York, for one, leaves
     * out Social Security and the first $20,000 of pension and IRA income —
     * and none of that is modelled. For a retiree it will tend to overstate.
     */
    public function stateAndLocalTax(float $grossIncome, Profile $profile, float $index = 1.0): float
    {
        return $this->stateAndLocalTaxFor($profile)($grossIncome, $index);
    }

    /**
     * stateAndLocalTax() for one profile, as a function of income alone.
     *
     * For a caller that taxes the same profile thousands of times — the
     * Retirement Strategizer's optimiser. Reading the brackets off the model
     * decodes their JSON on every access, which is nothing once and most of
     * the run time in a loop; this reads them once and closes over the result.
     *
     * @return Closure(float, float=): float
     */
    public function stateAndLocalTaxFor(Profile $profile): Closure
    {
        $stateDeduction = $profile->state_deduction;
        $stateBrackets = $this->pairs($profile->state_brackets);
        $localDeduction = $profile->local_deduction;
        $localBrackets = $this->pairs($profile->local_brackets);

        return fn (float $grossIncome, float $index = 1.0): float => $this->progressive(max(0.0, $grossIncome - $stateDeduction * $index), $stateBrackets, $index)
            + $this->progressive(max(0.0, $grossIncome - $localDeduction * $index), $localBrackets, $index);
    }

    /**
     * totalTax() for one profile, as a function of income alone. See
     * stateAndLocalTaxFor() for why.
     *
     * @return Closure(float, float=): float
     */
    public function totalTaxFor(Profile $profile): Closure
    {
        $status = $profile->filing_status;
        $federal = $this->forProfile($profile);
        $stateAndLocal = $this->stateAndLocalTaxFor($profile);

        return fn (float $grossIncome, float $index = 1.0): float => $federal->tax($grossIncome, $status, $index) + $stateAndLocal($grossIncome, $index);
    }

    /**
     * Tax on a year of a household's incomes, as a function of what each tax
     * treatment came to. See stateAndLocalTaxFor() for why it is a closure.
     *
     * `$amounts` is keyed by treatment (config `finance.flow_taxations`):
     * `['w2' => 90000, 'capital_gains' => 12000]`. Untaxed income is simply
     * left out.
     *
     * Kept blunt on purpose:
     *  - ordinary income, less the standard deduction, goes through the
     *    income-tax brackets;
     *  - capital gains sit on top of it and go through the capital gains
     *    brackets from wherever ordinary income left off, after whatever of
     *    the deduction ordinary income did not use;
     *  - payroll tax is a flat share of the profile's self-employment rate on
     *    the whole amount: all of it for the self-employed, half for a wage;
     *  - state and local tax treat gains as ordinary income.
     *
     * `$age` is the filer's age that year, for the additional deduction from
     * 65; null leaves it out.
     *
     * @return Closure(array<string, float>, float=, int|null=): array{income: float, capital_gains: float, payroll: float, state_local: float, total: float}
     */
    public function flowTaxFor(Profile $profile): Closure
    {
        $status = $profile->filing_status;
        $federal = $this->forProfile($profile);
        $brackets = $this->brackets($status);
        $gainsBrackets = $this->capitalGainsBrackets($profile);
        $stateAndLocal = $this->stateAndLocalTaxFor($profile);
        $payrollRate = $profile->se_tax_rate / 100;
        $taxations = config('finance.flow_taxations');

        return function (array $amounts, float $index = 1.0, ?int $age = null) use ($status, $federal, $brackets, $gainsBrackets, $stateAndLocal, $payrollRate, $taxations): array {
            $ordinary = 0.0;
            $gains = 0.0;
            $payroll = 0.0;

            foreach ($amounts as $taxation => $amount) {
                if (! isset($taxations[$taxation])) {
                    continue;
                }

                if ($taxations[$taxation]['schedule'] === 'capital_gains') {
                    $gains += $amount;
                } else {
                    $ordinary += $amount;
                }

                $payroll += $amount * $taxations[$taxation]['payroll'] * $payrollRate;
            }

            $deduction = $federal->deduction($status, $index, $age);
            $taxableOrdinary = max(0.0, $ordinary - $deduction);
            $taxableGains = max(0.0, $gains - max(0.0, $deduction - $ordinary));

            $income = $this->progressive($taxableOrdinary, $brackets, $index);
            $capitalGains = $this->progressive($taxableOrdinary + $taxableGains, $gainsBrackets, $index) - $this->progressive($taxableOrdinary, $gainsBrackets, $index);
            $stateLocal = $stateAndLocal($ordinary + $gains, $index);

            return [
                'income' => $income,
                'capital_gains' => $capitalGains,
                'payroll' => $payroll,
                'state_local' => $stateLocal,
                'total' => $income + $capitalGains + $payroll + $stateLocal,
            ];
        };
    }

    /**
     * The tax a month on what is coming in right now: the run rate of every
     * income that is running, taken as a year, taxed, and divided back down.
     *
     * @param  Collection<int, Flow>  $flows
     */
    public function monthlyRunRateTax(Collection $flows, Profile $profile): float
    {
        $amounts = $flows->where('direction', 'income')->whereNotNull('taxation')
            ->groupBy('taxation')
            ->map(fn (Collection $group): float => (float) $group->sum(fn (Flow $flow): float => $flow->current_monthly_amount * 12 * $flow->taxable_share))
            ->all();

        return $this->flowTaxFor($profile)($amounts, 1.0, $profile->age)['total'] / 12;
    }

    /**
     * The long-term capital gains brackets a profile is taxed on: its own
     * when it has set them, otherwise the built-in ones for its filing status.
     *
     * @return list<array{0: int|float, 1: int|float|null}>
     */
    public function capitalGainsBrackets(Profile $profile): array
    {
        if ($profile->ltcg_brackets !== null) {
            return $this->pairs($profile->ltcg_brackets);
        }

        return config("finance.tax.capital_gains_brackets.{$profile->filing_status}") ?? config('finance.tax.capital_gains_brackets.single');
    }

    /**
     * Everything owed on a year's ordinary income: federal, state and local.
     */
    public function totalTax(float $grossIncome, Profile $profile, float $index = 1.0): float
    {
        return $this->totalTaxFor($profile)($grossIncome, $index);
    }

    /**
     * Tax on a taxable income under a progressive table: each slice of income
     * at its own bracket's rate.
     *
     * @param  list<array{0: int|float, 1: int|float|null}>  $brackets  [rate %, ceiling], null on the open top bracket.
     */
    public function progressive(float $taxable, array $brackets, float $index = 1.0): float
    {
        $tax = 0.0;
        $floor = 0.0;

        foreach ($brackets as [$rate, $ceiling]) {
            $ceiling = $ceiling === null ? INF : $ceiling * $index;

            if ($taxable <= $floor) {
                break;
            }

            $tax += (min($taxable, $ceiling) - $floor) * $rate / 100;
            $floor = $ceiling;
        }

        return $tax;
    }

    /**
     * A profile's saved `{rate, up_to}` brackets as the [rate, ceiling] pairs
     * progressive() reads.
     *
     * @param  list<array{rate: int|float, up_to: int|float|null}>|null  $brackets
     * @return list<array{0: float, 1: float|null}>
     */
    private function pairs(?array $brackets): array
    {
        return array_map(
            fn (array $bracket): array => [(float) $bracket['rate'], $bracket['up_to'] === null ? null : (float) $bracket['up_to']],
            $brackets ?? [],
        );
    }

    /**
     * The rate the next dollar of income would be taxed at.
     */
    public function marginalRate(float $grossIncome, string $status, float $index = 1.0, ?int $age = null): float
    {
        $taxable = $grossIncome - $this->deduction($status, $index, $age);

        if ($taxable < 0) {
            return 0.0;
        }

        foreach ($this->brackets($status) as [$rate, $ceiling]) {
            if ($ceiling === null || $taxable < $ceiling * $index) {
                return (float) $rate;
            }
        }

        return 0.0;
    }

    /**
     * The gross income at which the bracket taxed at $rate tops out — the
     * deduction included, so it compares directly with a gross figure. Null
     * for a rate that is not on the table, or for the open top bracket.
     *
     * Paired with marginalRate(), this answers "how much more income fits in
     * the bracket I am already in": grossCeiling(marginalRate($income)).
     */
    public function grossCeiling(float $rate, string $status, float $index = 1.0, ?int $age = null): ?float
    {
        // The 0% "bracket" is the standard deduction: income inside it is
        // not taxed, and it tops out where the first real bracket begins.
        if ($rate === 0.0) {
            return $this->deduction($status, $index, $age);
        }

        foreach ($this->brackets($status) as [$bracketRate, $ceiling]) {
            if ((float) $bracketRate === $rate && $ceiling !== null) {
                return $ceiling * $index + $this->deduction($status, $index, $age);
            }
        }

        return null;
    }

    /**
     * The standard deduction, plus the additional one for the aged when an
     * age of 65 or more is given.
     */
    public function deduction(string $status, float $index = 1.0, ?int $age = null): float
    {
        $table = $this->table($status);
        $additional = $age !== null && $age >= $table['additional_age'] ? $table['additional_deduction'] : 0.0;

        return ($table['deduction'] + $additional) * $index;
    }

    /**
     * @return list<array{0: int|float, 1: int|float|null}>
     */
    public function brackets(string $status): array
    {
        return $this->table($status)['brackets'];
    }

    /**
     * The federal table for a filing status, read from config once per
     * instance. An unknown status gets the single filer's. The deduction is
     * the profile's own on an instance from forProfile() that has one.
     *
     * Everything deduction() reads is kept here too: it is called for every
     * tax worked out, hundreds of times in one simulated plan.
     *
     * @return array{deduction: float, additional_deduction: float, additional_age: int, brackets: list<array{0: int|float, 1: int|float|null}>}
     */
    private function table(string $status): array
    {
        return $this->tables[$status] ??= [
            'deduction' => $this->deduction ?? (float) config("finance.tax.standard_deduction.{$status}", config('finance.tax.standard_deduction.single')),
            'additional_deduction' => (float) config("finance.tax.additional_deduction.{$status}", config('finance.tax.additional_deduction.single')),
            'additional_age' => (int) config('finance.tax.additional_deduction.age'),
            'brackets' => config("finance.tax.brackets.{$status}") ?? config('finance.tax.brackets.single'),
        ];
    }
}
