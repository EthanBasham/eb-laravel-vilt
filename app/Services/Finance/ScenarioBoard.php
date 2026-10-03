<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\User;

/**
 * Projections & scenarios: every income and expense, year by year from now to
 * the end of the plan, under one scenario's assumptions.
 *
 * A year's figure for a flow is settled in two steps. The rate decides what it
 * would be — the scenario's rate for that flow when it has one, the flow's own
 * when it does not — through Flow::amountInYear(), so start and end dates and
 * the stop at retirement all still apply. Then a year the scenario has pinned
 * takes the pinned amount instead, and only that year: a pin does not move the
 * years after it.
 *
 * Everything is in nominal dollars — the amounts as they would be written on
 * the day, not deflated to today's.
 *
 * Each year's income is then taxed as a whole (TaxCalculator::flowTaxFor()),
 * by the treatment each flow carries, on tables indexed to that year by one
 * flat rate — the scenario's own when it has set one, the profile's inflation
 * rate when it has not, and always the profile's for the baseline — so "left over" is after tax. A flow's own row
 * stays gross: tax is a property of the year's income together, and has no
 * honest share to hang on one flow.
 */
class ScenarioBoard
{
    public function __construct(
        private Fleet $fleet,
        private TaxCalculator $tax,
    ) {}

    /**
     * The list page: each scenario's lifetime totals beside the baseline's,
     * the baseline being every flow projected as it stands.
     *
     * @return array<string, mixed>
     */
    public function listFor(User $user): array
    {
        $profile = Profile::for($user);
        $flows = $this->fleet->flows($user);
        $baseline = $this->totals($this->rows($flows, collect(), $profile), $profile);

        $scenarios = Scenario::query()->onlyOwnedBy($user)->inDefaultOrder()->with('scenarioFlows')->get();

        return [
            'horizon' => $this->horizon($profile),
            'has_flows' => $flows->isNotEmpty(),
            'baseline' => ['summary' => $this->summary($baseline), 'totals' => $baseline],
            'scenarios' => $scenarios->map(function (Scenario $scenario) use ($flows, $profile, $baseline): array {
                $totals = $this->totals($this->rows($flows, $scenario->scenarioFlows, $profile), $profile, $scenario->bracket_inflation_rate);

                return [
                    ...$scenario->props,
                    'adjusted_count' => $scenario->scenarioFlows->whereIn('flow_id', $flows->modelKeys())->count(),
                    'summary' => $this->summary($totals),
                    'net_vs_baseline' => round($this->summary($totals)['net'] - $this->summary($baseline)['net'], 2),
                    'left_over' => $this->leftOver($totals),
                    'totals' => $totals,
                ];
            })->values(),
        ];
    }

    /**
     * One scenario's page: every flow with its series, and the totals.
     *
     * @return array<string, mixed>
     */
    public function for(User $user, Scenario $scenario): array
    {
        $profile = Profile::for($user);
        $flows = $this->fleet->flows($user);

        $rows = $this->rows($flows, $scenario->scenarioFlows, $profile);
        $totals = $this->totals($rows, $profile, $scenario->bracket_inflation_rate);
        $baseline = $this->summary($this->totals($this->rows($flows, collect(), $profile), $profile));

        return [
            'scenario' => $scenario->props,
            'horizon' => $this->horizon($profile),
            // The rate the tax tables rise by in this scenario, and the
            // profile's, which it falls back to.
            'bracket_inflation' => [
                'rate' => $scenario->bracket_inflation_rate ?? $profile->inflation_rate,
                'is_own' => $scenario->bracket_inflation_rate !== null,
                'profile_rate' => $profile->inflation_rate,
            ],
            'income' => $rows->where('direction', 'income')->values(),
            'expenses' => $rows->where('direction', 'expense')->values(),
            'totals' => $totals,
            'summary' => $this->summary($totals),
            'net_vs_baseline' => round($this->summary($totals)['net'] - $baseline['net'], 2),
        ];
    }

    /**
     * Each flow as the page lists it, under a scenario's settings.
     *
     * @param  Collection<int, Flow>  $flows
     * @param  Collection<int, ScenarioFlow>  $settings  Empty for the baseline.
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $flows, Collection $settings, Profile $profile): Collection
    {
        $settings = $settings->keyBy('flow_id');

        return $flows->map(fn (Flow $flow): array => $this->row($flow, $settings->get($flow->id), $profile))->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Flow $flow, ?ScenarioFlow $settings, Profile $profile): array
    {
        $rate = $settings?->annual_growth_rate ?? $flow->annual_growth_rate;
        $pins = $settings?->overrides ?? [];

        $series = array_map(function (int $year) use ($flow, $profile, $rate, $pins): array {
            $base = round($flow->amountInYear($year, $profile->retirement_year, $rate), 2);
            $isPinned = array_key_exists($year, $pins);

            return [
                'year' => $year,
                'age' => $year - $profile->birth_year,
                // What the rate alone makes of the year, kept beside the
                // figure used so the page can draw both and undo a pin.
                'base' => $base,
                'amount' => $isPinned ? round((float) $pins[$year], 2) : $base,
                'is_pinned' => $isPinned,
            ];
        }, $this->years($profile));

        return [
            'id' => $flow->id,
            'name' => $flow->name,
            'direction' => $flow->direction,
            'category_label' => $flow->category_label,
            'frequency_label' => config("finance.frequencies.{$flow->frequency}.label", $flow->frequency),
            'holding_name' => $flow->holding?->name,
            // A one-time amount lands once; no rate has anything to compound.
            'is_one_time' => $flow->frequency === 'once',
            'taxation' => $flow->taxation,
            'taxation_label' => config("finance.flow_taxations.{$flow->taxation}.label"),
            'taxed_portion' => $flow->taxed_portion,
            'taxable_share' => $flow->taxable_share,
            'own_rate' => $flow->annual_growth_rate,
            'rate' => $rate,
            'has_scenario_rate' => $settings?->annual_growth_rate !== null,
            'pinned_count' => count(array_filter($series, fn (array $point): bool => $point['is_pinned'])),
            'total' => round(array_sum(array_column($series, 'amount')), 2),
            'series' => $series,
        ];
    }

    /**
     * Each year of the plan before any tax: what comes in, what goes out, and
     * how much of the income is taxed under each treatment. Keyed by year.
     *
     * Public for the Retirement Strategizer, which adds RMDs and conversions
     * to a year's income before taxing it and so cannot use totals().
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{year: int, age: int, income: float, expenses: float, taxed: array<string, float>}>
     */
    public function yearly(Collection $rows): array
    {
        $years = [];

        foreach ($rows as $row) {
            foreach ($row['series'] as $point) {
                $years[$point['year']] ??= ['year' => $point['year'], 'age' => $point['age'], 'income' => 0.0, 'expenses' => 0.0, 'taxed' => []];
                $years[$point['year']][$row['direction'] === 'income' ? 'income' : 'expenses'] += $point['amount'];

                if ($row['taxation'] !== null) {
                    $years[$point['year']]['taxed'][$row['taxation']] = ($years[$point['year']]['taxed'][$row['taxation']] ?? 0.0) + $point['amount'] * $row['taxable_share'];
                }
            }
        }

        return $years;
    }

    /**
     * Income, the tax on it, expenses and what is left, for each year of the
     * plan. `take_home` is income after tax; `net` is that less expenses.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  float|null  $bracketInflationRate  A scenario's own rate for the tax tables; null uses the profile's inflation.
     * @return list<array{year: int, age: int, income: float, taxes: float, take_home: float, expenses: float, net: float}>
     */
    private function totals(Collection $rows, Profile $profile, ?float $bracketInflationRate = null): array
    {
        $totals = $this->yearly($rows);

        $taxOn = $this->tax->flowTaxFor($profile);
        $inflation = 1 + ($bracketInflationRate ?? $profile->inflation_rate) / 100;
        $thisYear = now()->year;

        return array_values(array_map(function (array $year) use ($taxOn, $inflation, $thisYear): array {
            // The tables rise each year, as the IRS raises them; held still,
            // a growing income would drift up the brackets for nothing.
            $taxes = $taxOn($year['taxed'], $inflation ** ($year['year'] - $thisYear))['total'];

            return [
                'year' => $year['year'],
                'age' => $year['age'],
                'income' => round($year['income'], 2),
                'taxes' => round($taxes, 2),
                'take_home' => round($year['income'] - $taxes, 2),
                'expenses' => round($year['expenses'], 2),
                'net' => round($year['income'] - $taxes - $year['expenses'], 2),
            ];
        }, $totals));
    }

    /**
     * The whole plan added up.
     *
     * @param  list<array<string, int|float>>  $totals
     * @return array{income: float, taxes: float, expenses: float, net: float}
     */
    private function summary(array $totals): array
    {
        return [
            'income' => round(array_sum(array_column($totals, 'income')), 2),
            'taxes' => round(array_sum(array_column($totals, 'taxes')), 2),
            'expenses' => round(array_sum(array_column($totals, 'expenses')), 2),
            'net' => round(array_sum(array_column($totals, 'net')), 2),
        ];
    }

    /**
     * What is left over the whole plan, and in its leanest and best years.
     * The total is the headline because a negative one is what savings and
     * retirement accounts would have to cover across the plan.
     * Null when there is nothing to project. On a tie the earlier year is named.
     *
     * @param  list<array<string, int|float>>  $totals
     * @return array{total: float, min: array{amount: float, year: int}, max: array{amount: float, year: int}}|null
     */
    private function leftOver(array $totals): ?array
    {
        if ($totals === []) {
            return null;
        }

        $years = collect($totals);
        $leanest = $years->firstWhere('net', $years->min('net'));
        $best = $years->firstWhere('net', $years->max('net'));

        return [
            'total' => round($years->sum('net'), 2),
            'min' => ['amount' => $leanest['net'], 'year' => $leanest['year']],
            'max' => ['amount' => $best['net'], 'year' => $best['year']],
        ];
    }

    /**
     * This year through the year the profile plans to — never fewer than the
     * one year, for someone already past it.
     *
     * @return list<int>
     */
    private function years(Profile $profile): array
    {
        $from = now()->year;

        return range($from, max($from, $profile->birth_year + $profile->life_expectancy));
    }

    /**
     * @return array{from: int, to: int, retirement_year: int, life_expectancy: int, has_birth_date: bool}
     */
    private function horizon(Profile $profile): array
    {
        $years = $this->years($profile);

        return [
            'from' => $years[0],
            'to' => $years[array_key_last($years)],
            'retirement_year' => $profile->retirement_year,
            'life_expectancy' => $profile->life_expectancy,
            'has_birth_date' => $profile->birth_date !== null,
        ];
    }
}
