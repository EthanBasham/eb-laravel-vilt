<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Armada;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\Finance\ScenarioHolding;
use App\Models\Finance\Transfer;
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
 * years after it — unless the scenario also starts the rate again from it, in
 * which case the years after compound from the pinned amount, up to the next
 * year it starts again from.
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
 *
 * A compound flow — "Household expenses" with items inside — is projected
 * item by item and listed as one group: its rate, when the scenario gives it
 * one, reaches every item that has none of its own.
 *
 * The holdings are projected beside the flows, by FleetLedger, so what an
 * asset earns is kept apart from the money moved into or out of it. A
 * scenario may give a holding its own rate and monthly contribution, and pin
 * a year-end to a value; the years after a pinned one carry on from it.
 */
class ScenarioBoard
{
    public function __construct(
        private Fleet $fleet,
        private TaxCalculator $tax,
        private FleetLedger $ledger,
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

        $baselineNet = $this->summary($baseline)['net'];
        // A setting counts wherever it is: on a flow, on a group, or on an
        // item inside one.
        $flowIds = $flows->modelKeys();
        $itemIds = $this->fleet->flowLeaves($flows)->pluck('id')->all();

        $scenarios = Scenario::query()->onlyOwnedBy($user)->inDefaultOrder()->with('scenarioFlows')->get();

        return [
            'horizon' => $this->horizon($profile),
            'has_flows' => $flows->isNotEmpty(),
            'baseline' => ['summary' => $this->summary($baseline), 'totals' => $baseline],
            'scenarios' => $scenarios->map(function (Scenario $scenario) use ($flows, $profile, $baselineNet, $flowIds, $itemIds): array {
                $totals = $this->totals($this->rows($flows, $scenario->scenarioFlows, $profile), $profile, $scenario->bracket_inflation_rate);
                $summary = $this->summary($totals);

                return [
                    ...$scenario->props,
                    'adjusted_count' => $scenario->scenarioFlows->whereIn('flow_id', [...$flowIds, ...$itemIds])->unique('flow_id')->count(),
                    'summary' => $summary,
                    'net_vs_baseline' => round($summary['net'] - $baselineNet, 2),
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
        $listed = $this->grouped($flows, $rows, $scenario->scenarioFlows);
        $holdings = $this->holdings($user, $rows, $totals, $scenario->scenarioHoldings, $profile);

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
            'income' => $listed->where('direction', 'income')->values(),
            'expenses' => $listed->where('direction', 'expense')->values(),
            'totals' => $totals,
            'summary' => $this->summary($totals),
            'net_vs_baseline' => round($this->summary($totals)['net'] - $baseline['net'], 2),
            'assets' => $holdings['rows']->where('side', 'asset')->values(),
            'liabilities' => $holdings['rows']->where('side', 'liability')->values(),
            'net_worth' => $holdings['net_worth'],
            'unfunded' => $holdings['unfunded'],
            'armadas' => $this->byArmada($user, $rows, $holdings['rows']),
        ];
    }

    /**
     * The fleet's holdings year by year under a scenario: each leaf with its
     * series, and the two sides of the balance sheet added up.
     *
     * Public for ArmadaBoard, which projects one armada's holdings as they
     * stand.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  The flows, from rows().
     * @param  list<array<string, int|float>>  $totals  From totals(): the tax each year's routed income loses comes from here.
     * @param  Collection<int, ScenarioHolding>  $settings  Empty for the baseline.
     * @return array{rows: Collection<int, array<string, mixed>>, net_worth: list<array<string, int|float>>, unfunded: float}
     */
    public function holdings(User $user, Collection $rows, array $totals, Collection $settings, Profile $profile): array
    {
        $leaves = $this->fleet->leaves($this->fleet->holdings($user));
        $years = $this->years($profile);
        $transfers = Transfer::query()->onlyOwnedBy($user)->onlyActive()->inDefaultOrder()->get();

        $settings = $settings->keyBy('holding_id')
            ->map(fn (ScenarioHolding $setting): array => $setting->only(['annual_rate', 'monthly_contribution', 'overrides']))
            ->all();

        $routed = $rows->whereNotNull('account_id')->map(fn (array $row): array => [
            'account_id' => $row['account_id'],
            'direction' => $row['direction'],
            'taxable_share' => $row['taxable_share'],
            'amounts' => array_column($row['series'], 'amount', 'year'),
        ])->values()->all();

        $taxRates = $this->taxRates($rows, $totals);
        $retirementYear = $profile->retirement_year;

        $run = fn (array $settings): array => $this->ledger->run($leaves, $years, $routed, $transfers, $settings, $taxRates, $retirementYear);

        $ledger = $run($settings);

        // What each year would have come to with nothing pinned, for the
        // dashed line and to undo a pin. Only worked out when something is.
        $hasPins = collect($settings)->contains(fn (array $setting): bool => ! empty($setting['overrides']));
        $unpinned = $hasPins ? $run(array_map(fn (array $setting): array => [...$setting, 'overrides' => null], $settings)) : $ledger;

        $birthYear = $profile->birth_year;

        return [
            'rows' => $leaves->map(function (Holding $holding) use ($ledger, $unpinned, $settings, $birthYear): array {
                $setting = $settings[$holding->id] ?? [];
                $years = $ledger['holdings'][$holding->id];

                $series = array_map(fn (array $year, array $base): array => [
                    'year' => $year['year'],
                    'age' => $year['year'] - $birthYear,
                    'base' => round($base['end'], 2),
                    'amount' => round($year['end'], 2),
                    'is_pinned' => $year['is_pinned'],
                    'start' => round($year['start'], 2),
                    'growth' => round($year['growth'], 2),
                    'contributions' => round($year['contributions'], 2),
                    'deposits' => round($year['deposits'], 2),
                    'withdrawals' => round($year['withdrawals'], 2),
                    'transfers_in' => round($year['transfers_in'], 2),
                    'transfers_out' => round($year['transfers_out'], 2),
                    // Money moved in the year, less money moved out. For a
                    // debt both payments and transfers in bring the balance
                    // down.
                    'moved' => round($holding->is_asset
                        ? $year['contributions'] + $year['deposits'] + $year['transfers_in'] - $year['withdrawals'] - $year['transfers_out']
                        : $year['contributions'] + $year['transfers_in'], 2),
                    'unfunded' => round($year['unfunded'], 2),
                ], $years, $unpinned['holdings'][$holding->id]);

                return [
                    'id' => $holding->id,
                    'name' => $holding->full_name,
                    'side' => $holding->side,
                    'type_label' => $holding->type_label,
                    'armada_id' => $holding->armada_key,
                    'own_rate' => round($holding->expected_rate, 3),
                    'rate' => $setting['annual_rate'] ?? round($holding->expected_rate, 3),
                    'has_scenario_rate' => ($setting['annual_rate'] ?? null) !== null,
                    'own_contribution' => $holding->monthly_contribution,
                    'contribution' => $setting['monthly_contribution'] ?? $holding->monthly_contribution,
                    'has_scenario_contribution' => ($setting['monthly_contribution'] ?? null) !== null,
                    'pinned_count' => count(array_filter($series, fn (array $point): bool => $point['is_pinned'])),
                    'start' => round($holding->value, 2),
                    'end' => $series === [] ? round($holding->value, 2) : $series[array_key_last($series)]['amount'],
                    // The two halves of how it got there, over the whole plan.
                    'growth' => round(array_sum(array_column($series, 'growth')), 2),
                    'moved' => round(array_sum(array_column($series, 'moved')), 2),
                    'unfunded' => round(array_sum(array_column($series, 'unfunded')), 2),
                    'series' => $series,
                ];
            })->values(),
            'net_worth' => array_map(fn (array $year): array => [
                'year' => $year['year'],
                'age' => $year['year'] - $birthYear,
                'assets' => round($year['assets'], 2),
                'liabilities' => round($year['liabilities'], 2),
                'net_worth' => round($year['net_worth'], 2),
            ], $ledger['totals']),
            'unfunded' => round(array_sum(array_column($ledger['totals'], 'unfunded')), 2),
        ];
    }

    /**
     * The share of a taxed income each year loses to tax: the year's tax
     * over everything taxed in it. What FleetLedger nets a routed income by.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array<string, int|float>>  $totals
     * @return array<int, float>
     */
    private function taxRates(Collection $rows, array $totals): array
    {
        $taxed = array_map(fn (array $year): float => array_sum($year['taxed']), $this->yearly($rows));
        $rates = [];

        foreach ($totals as $year) {
            $rates[$year['year']] = ($taxed[$year['year']] ?? 0.0) > 0 ? $year['taxes'] / $taxed[$year['year']] : 0.0;
        }

        return $rates;
    }

    /**
     * The plan added up armada by armada: what each brings in and spends
     * over the whole of it, and what its holdings are worth at each end.
     * Whatever belongs to no armada is the last row, when there is any.
     *
     * Public for ArmadaBoard.
     *
     * @param  Collection<int, array<string, mixed>>  $flowRows
     * @param  Collection<int, array<string, mixed>>  $holdingRows
     * @return list<array<string, mixed>>
     */
    public function byArmada(User $user, Collection $flowRows, Collection $holdingRows): array
    {
        $armadas = Armada::query()->onlyOwnedBy($user)->inDefaultOrder()->get()->map->only(['id', 'name']);

        $hasUnassigned = $flowRows->contains('armada_id', null) || $holdingRows->contains('armada_id', null);

        if ($armadas->isEmpty()) {
            return [];
        }

        if ($hasUnassigned) {
            $armadas->push(['id' => null, 'name' => 'Unassigned']);
        }

        $signed = fn (Collection $holdings, string $key): float => (float) $holdings->sum(fn (array $row): float => $row['side'] === 'asset' ? $row[$key] : -$row[$key]);

        return $armadas->map(function (array $armada) use ($flowRows, $holdingRows, $signed): array {
            $flows = $flowRows->where('armada_id', $armada['id']);
            $holdings = $holdingRows->where('armada_id', $armada['id']);
            $income = (float) $flows->where('direction', 'income')->sum('total');
            $expenses = (float) $flows->where('direction', 'expense')->sum('total');

            return [
                ...$armada,
                'income' => round($income, 2),
                'expenses' => round($expenses, 2),
                'cashflow' => round($income - $expenses, 2),
                'net_worth_start' => round($signed($holdings, 'start'), 2),
                'net_worth_end' => round($signed($holdings, 'end'), 2),
                'growth' => round($signed($holdings->where('side', 'asset'), 'growth') - (float) $holdings->where('side', 'liability')->sum('growth'), 2),
                'holdings_count' => $holdings->count(),
                'flows_count' => $flows->count(),
            ];
        })->values()->all();
    }

    /**
     * Each flow that carries an amount of its own, under a scenario's
     * settings: a compound flow is replaced by its items, so adding these up
     * counts every dollar once.
     *
     * An item with no setting of its own takes the rate the scenario gave the
     * flow it sits inside, if it gave one.
     *
     * @param  Collection<int, Flow>  $flows  As listed — Fleet::flows().
     * @param  Collection<int, ScenarioFlow>  $settings  Empty for the baseline.
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $flows, Collection $settings, Profile $profile): Collection
    {
        $settings = $settings->keyBy('flow_id');

        // Worked out once for every row: each reads the profile's birth
        // date, which is parsed afresh on every read.
        $plan = ['years' => $this->years($profile), 'retirement_year' => $profile->retirement_year, 'birth_year' => $profile->birth_year];

        return $this->fleet->flowLeaves($flows)
            ->map(fn (Flow $flow): array => $this->row($flow, $settings->get($flow->id), $plan, $settings->get($flow->parent_id)?->annual_growth_rate))
            ->values();
    }

    /**
     * The rows as the page lists them: each compound flow as one group
     * carrying its items, everything else as it is.
     *
     * A group's series is its items' added up. It has no pins of its own —
     * a year is moved on the item it belongs to.
     *
     * @param  Collection<int, Flow>  $flows
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, ScenarioFlow>  $settings
     * @return Collection<int, array<string, mixed>>
     */
    private function grouped(Collection $flows, Collection $rows, Collection $settings): Collection
    {
        $settings = $settings->keyBy('flow_id');
        $rows = $rows->keyBy('id');

        return $flows->map(function (Flow $flow) use ($rows, $settings): array {
            if (! $flow->is_compound) {
                return $rows[$flow->id];
            }

            $items = $flow->children->map(fn (Flow $item): array => $rows[$item->id])->values();
            $series = [];

            foreach ($items as $item) {
                foreach ($item['series'] as $index => $point) {
                    $series[$index] ??= ['year' => $point['year'], 'age' => $point['age'], 'base' => 0.0, 'amount' => 0.0, 'is_pinned' => false];
                    $series[$index]['base'] = round($series[$index]['base'] + $point['base'], 2);
                    $series[$index]['amount'] = round($series[$index]['amount'] + $point['amount'], 2);
                }
            }

            $rate = $settings->get($flow->id)?->annual_growth_rate;

            return [
                'id' => $flow->id,
                'name' => $flow->name,
                'direction' => $flow->direction,
                'category_label' => $flow->category_label,
                'armada_id' => $flow->armada_key,
                'account_name' => $flow->account?->name,
                'is_group' => true,
                // Null leaves each item on its own rate.
                'rate' => $rate,
                'has_scenario_rate' => $rate !== null,
                'pinned_count' => (int) $items->sum('pinned_count'),
                'total' => round((float) $items->sum('total'), 2),
                'series' => $series,
                'items' => $items,
            ];
        })->values();
    }

    /**
     * @param  array{years: list<int>, retirement_year: int, birth_year: int}  $plan
     * @param  float|null  $groupRate  The rate the scenario gave the flow this one sits inside.
     * @return array<string, mixed>
     */
    private function row(Flow $flow, ?ScenarioFlow $settings, array $plan, ?float $groupRate = null): array
    {
        $rate = $settings?->annual_growth_rate ?? $groupRate ?? $flow->annual_growth_rate;
        $pins = $settings?->overrides ?? [];
        $restarts = $settings?->restarts ?? [];

        // The latest pinned year the rate started again from, and its amount.
        $restart = null;
        $series = [];

        foreach ($plan['years'] as $year) {
            $base = round($flow->amountInYear($year, $plan['retirement_year'], $rate), 2);
            $unpinned = $restart === null ? $base : round($flow->amountInYear($year, $plan['retirement_year'], $rate, $restart), 2);
            $isPinned = array_key_exists($year, $pins);
            $isRestart = $isPinned && in_array($year, $restarts, true);
            $amount = $isPinned ? round((float) $pins[$year], 2) : $unpinned;

            $series[] = [
                'year' => $year,
                'age' => $year - $plan['birth_year'],
                // What the rate alone makes of the year, from today and with
                // nothing set by hand: the line the page draws beside the
                // figure used.
                'base' => $base,
                // What the year comes to with no pin of its own, which is
                // what undoing its pin gives: the rate, from wherever it
                // last started again.
                'unpinned' => $unpinned,
                'amount' => $amount,
                'is_pinned' => $isPinned,
                'is_restart' => $isRestart,
            ];

            if ($isRestart) {
                $restart = ['year' => $year, 'amount' => $amount];
            }
        }

        return [
            'id' => $flow->id,
            'parent_id' => $flow->parent_id,
            'name' => $flow->name,
            'direction' => $flow->direction,
            'category' => $flow->category,
            'category_label' => $flow->category_label,
            'frequency_label' => config("finance.frequencies.{$flow->frequency}.label", $flow->frequency),
            'holding_name' => $flow->holding?->name,
            'armada_id' => $flow->armada_key,
            // The asset it is paid into or out of, if it names one.
            'account_id' => $flow->routed_account_id,
            'account_name' => ($flow->account ?? $flow->parent?->account)?->name,
            'is_group' => false,
            // A one-time amount lands once; no rate has anything to compound.
            'is_one_time' => $flow->frequency === 'once',
            'taxation' => $flow->taxation,
            'taxation_label' => config("finance.flow_taxations.{$flow->taxation}.label"),
            'taxed_portion' => $flow->taxed_portion,
            'taxable_share' => $flow->taxable_share,
            // What it falls back to with no rate of its own here: the rate
            // of the group it sits in, when the scenario set one.
            'own_rate' => $groupRate ?? $flow->annual_growth_rate,
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
     * Public for ArmadaBoard, which needs the baseline's tax to run the ledger.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  float|null  $bracketInflationRate  A scenario's own rate for the tax tables; null uses the profile's inflation.
     * @return list<array{year: int, age: int, income: float, taxes: float, take_home: float, expenses: float, net: float}>
     */
    public function totals(Collection $rows, Profile $profile, ?float $bracketInflationRate = null): array
    {
        $totals = $this->yearly($rows);

        $taxOn = $this->tax->flowTaxFor($profile);
        $inflation = 1 + ($bracketInflationRate ?? $profile->inflation_rate) / 100;
        $thisYear = now()->year;

        return array_values(array_map(function (array $year) use ($taxOn, $inflation, $thisYear): array {
            // The tables rise each year, as the IRS raises them; held still,
            // a growing income would drift up the brackets for nothing.
            $taxes = $taxOn($year['taxed'], $inflation ** ($year['year'] - $thisYear), $year['age'])['total'];

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
