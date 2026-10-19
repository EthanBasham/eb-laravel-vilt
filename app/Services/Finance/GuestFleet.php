<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use App\Models\User;

/**
 * Copies one user's fleet over another's — the owner's onto the guest
 * account — with every amount scaled and nothing left that names anybody.
 *
 * Names become generic ("Roth IRA", "W-2 salary", "Scenario 2"), and
 * institutions, notes and descriptions are dropped. Rates, ages, dates and
 * settings come across as they are, so the guest's tools reach the same
 * conclusions at a bigger size.
 *
 * Rows are copied column for column, so a column added later is copied
 * unchanged. That is right for a rate or a flag and wrong for money or free
 * text: a new amount or wording column has to be handled in its table's method
 * below, or the guest shows the owner's own figure or words.
 */
class GuestFleet
{
    private float $scale = 1.0;

    /**
     * Generic names handed out so far, by scope, so a second "Real estate"
     * becomes "Real estate 2".
     *
     * @var array<string, array<string, int>>
     */
    private array $named = [];

    public function __construct(private SampleFleet $sample) {}

    /**
     * Replaces everything the guest has in the sub-project with a copy of the
     * source's.
     */
    public function copy(User $source, User $guest, float $scale): void
    {
        $this->scale = $scale;
        $this->named = [];

        DB::transaction(function () use ($source, $guest): void {
            $this->sample->clear($guest);

            $this->copyProfile($source, $guest);

            $armadas = $this->copyArmadas($source, $guest);
            $holdings = $this->copyHoldings($source, $guest, $armadas);
            $this->copyPositions($holdings);
            $flows = $this->copyFlows($source, $guest, $holdings, $armadas);
            $this->copyActuals($source, $guest, $flows);
            $this->copyGoals($source, $guest);
            $this->copyTransfers($source, $guest, $holdings);

            $scenarios = $this->copyScenarios($source, $guest);
            $this->copyScenarioAdjustments($scenarios, $flows, $holdings);

            $this->copySocialSecurityStrategies($source, $guest);
            $this->copyWithdrawalStrategies($source, $guest, $scenarios);
            $strategies = $this->copyConversionStrategies($source, $guest);
            $this->copyConversionReport($source, $guest, $strategies, $scenarios);
            $this->copyMonteCarloRun($source, $guest);
            $this->copySnapshots($source, $guest);
        });
    }

    private function copyProfile(User $source, User $guest): void
    {
        foreach ($this->rowsOf('fin_profiles', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_profiles', [
                ...$row,
                'user_id' => $guest->id,
                'ss_monthly_benefit' => $this->money($row['ss_monthly_benefit']),
                'spouse_ss_monthly_benefit' => $this->money($row['spouse_ss_monthly_benefit']),
            ]);
        }
    }

    /**
     * @return array<int, int> New id by old.
     */
    private function copyArmadas(User $source, User $guest): array
    {
        $ids = [];

        foreach ($this->rowsOf('fin_armadas', 'user_id', [$source->id]) as $row) {
            $ids[$row['id']] = $this->insert('fin_armadas', [
                ...$row,
                'user_id' => $guest->id,
                'name' => $this->numbered('armadas', 'Armada', always: true),
                'description' => null,
            ]);
        }

        return $ids;
    }

    /**
     * Inserted with no parent and nothing securing them, then pointed at
     * their copies once every holding has one: either can refer to a holding
     * later in the list.
     *
     * @param  array<int, int>  $armadas
     * @return array<int, int> New id by old.
     */
    private function copyHoldings(User $source, User $guest, array $armadas): array
    {
        $rows = $this->rowsOf('fin_holdings', 'user_id', [$source->id]);
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['id']] = $this->insert('fin_holdings', [
                ...$row,
                'user_id' => $guest->id,
                'parent_id' => null,
                'secured_by_id' => null,
                'armada_id' => $this->mapped($armadas, $row['armada_id']),
                'name' => $this->numbered('holdings', $this->holdingLabel($row)),
                'institution' => null,
                'notes' => null,
                'balance' => $this->money($row['balance']),
                'monthly_contribution' => $this->money($row['monthly_contribution']),
            ]);
        }

        foreach ($rows as $row) {
            if ($row['parent_id'] !== null || $row['secured_by_id'] !== null) {
                DB::table('fin_holdings')->where('id', $ids[$row['id']])->update([
                    'parent_id' => $this->mapped($ids, $row['parent_id']),
                    'secured_by_id' => $this->mapped($ids, $row['secured_by_id']),
                ]);
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $holdings
     */
    private function copyPositions(array $holdings): void
    {
        foreach ($this->rowsOf('fin_positions', 'holding_id', array_keys($holdings)) as $row) {
            $holdingId = $holdings[$row['holding_id']];

            $this->insert('fin_positions', [
                ...$row,
                'holding_id' => $holdingId,
                'name' => $this->numbered("positions.{$holdingId}", config("finance.position_classes.{$row['asset_class']}.label") ?? 'Position'),
                'symbol' => null,
                'value' => $this->money($row['value']),
            ]);
        }
    }

    /**
     * Two passes, as for holdings: an item can be listed before the
     * household expense it belongs to.
     *
     * @param  array<int, int>  $holdings
     * @param  array<int, int>  $armadas
     * @return array<int, int> New id by old.
     */
    private function copyFlows(User $source, User $guest, array $holdings, array $armadas): array
    {
        $rows = $this->rowsOf('fin_flows', 'user_id', [$source->id]);
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['id']] = $this->insert('fin_flows', [
                ...$row,
                'user_id' => $guest->id,
                'parent_id' => null,
                'holding_id' => $this->mapped($holdings, $row['holding_id']),
                'account_id' => $this->mapped($holdings, $row['account_id']),
                'armada_id' => $this->mapped($armadas, $row['armada_id']),
                'name' => $this->numbered('flows', config("finance.flow_categories.{$row['direction']}.{$row['category']}.label") ?? 'Flow'),
                'amount' => $this->money($row['amount']),
            ]);
        }

        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                DB::table('fin_flows')->where('id', $ids[$row['id']])->update(['parent_id' => $this->mapped($ids, $row['parent_id'])]);
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $flows
     */
    private function copyActuals(User $source, User $guest, array $flows): void
    {
        foreach ($this->rowsOf('fin_actuals', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_actuals', [
                ...$row,
                'user_id' => $guest->id,
                'flow_id' => $flows[$row['flow_id']],
                'amount' => $this->money($row['amount']),
            ]);
        }
    }

    private function copyGoals(User $source, User $guest): void
    {
        foreach ($this->rowsOf('fin_goals', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_goals', [
                ...$row,
                'user_id' => $guest->id,
                'name' => $this->numbered('goals', 'Goal', always: true),
                'target_amount' => $this->money($row['target_amount']),
                'saved_amount' => $this->money($row['saved_amount']),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $holdings
     */
    private function copyTransfers(User $source, User $guest, array $holdings): void
    {
        foreach ($this->rowsOf('fin_transfers', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_transfers', [
                ...$row,
                'user_id' => $guest->id,
                'from_holding_id' => $holdings[$row['from_holding_id']],
                'to_holding_id' => $holdings[$row['to_holding_id']],
                'name' => $this->numbered('transfers', config("finance.transfer_kinds.{$row['kind']}.label") ?? 'Transfer'),
                'amount' => $this->money($row['amount']),
                'keep_balance' => $this->money($row['keep_balance']),
            ]);
        }
    }

    /**
     * @return array<int, int> New id by old.
     */
    private function copyScenarios(User $source, User $guest): array
    {
        $ids = [];

        foreach ($this->rowsOf('fin_scenarios', 'user_id', [$source->id]) as $row) {
            $ids[$row['id']] = $this->insert('fin_scenarios', [
                ...$row,
                'user_id' => $guest->id,
                'name' => $this->numbered('scenarios', 'Scenario', always: true),
                'description' => null,
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $scenarios
     * @param  array<int, int>  $flows
     * @param  array<int, int>  $holdings
     */
    private function copyScenarioAdjustments(array $scenarios, array $flows, array $holdings): void
    {
        foreach ($this->rowsOf('fin_scenario_flows', 'scenario_id', array_keys($scenarios)) as $row) {
            $this->insert('fin_scenario_flows', [
                ...$row,
                'scenario_id' => $scenarios[$row['scenario_id']],
                'flow_id' => $flows[$row['flow_id']],
                'overrides' => $this->moneyList($row['overrides']),
            ]);
        }

        foreach ($this->rowsOf('fin_scenario_holdings', 'scenario_id', array_keys($scenarios)) as $row) {
            $this->insert('fin_scenario_holdings', [
                ...$row,
                'scenario_id' => $scenarios[$row['scenario_id']],
                'holding_id' => $holdings[$row['holding_id']],
                'monthly_contribution' => $this->money($row['monthly_contribution']),
                'overrides' => $this->moneyList($row['overrides']),
            ]);
        }
    }

    private function copySocialSecurityStrategies(User $source, User $guest): void
    {
        foreach ($this->rowsOf('fin_social_security_strategies', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_social_security_strategies', [
                ...$row,
                'user_id' => $guest->id,
                'name' => $this->numbered('social_security', 'Strategy', always: true),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $scenarios
     */
    private function copyWithdrawalStrategies(User $source, User $guest, array $scenarios): void
    {
        foreach ($this->rowsOf('fin_withdrawal_strategies', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_withdrawal_strategies', [
                ...$row,
                'user_id' => $guest->id,
                'scenario_id' => $this->mapped($scenarios, $row['scenario_id']),
                'name' => $this->numbered('withdrawals', config("finance.withdrawal_strategies.{$row['kind']}.label") ?? 'Strategy'),
                'spending_amount' => $this->money($row['spending_amount']),
            ]);
        }
    }

    /**
     * @return array<int, int> New id by old.
     */
    private function copyConversionStrategies(User $source, User $guest): array
    {
        $ids = [];

        foreach ($this->rowsOf('fin_conversion_strategies', 'user_id', [$source->id]) as $row) {
            $ids[$row['id']] = $this->insert('fin_conversion_strategies', [
                ...$row,
                'user_id' => $guest->id,
                'name' => $this->numbered('conversions', config("finance.conversion_strategies.{$row['kind']}.label") ?? 'Strategy'),
                'heir_income' => $this->money($row['heir_income']),
                'tax_outside_amount' => $this->money($row['tax_outside_amount']),
                'conversion_amount' => $this->money($row['conversion_amount']),
                'overrides' => $this->conversionOverrides($row['overrides']),
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $strategies
     * @param  array<int, int>  $scenarios
     */
    private function copyConversionReport(User $source, User $guest, array $strategies, array $scenarios): void
    {
        foreach ($this->rowsOf('fin_conversion_report_entries', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_conversion_report_entries', [
                ...$row,
                'user_id' => $guest->id,
                'conversion_strategy_id' => $strategies[$row['conversion_strategy_id']],
                'scenario_id' => $this->mapped($scenarios, $row['scenario_id']),
            ]);
        }
    }

    /**
     * The settings only. Stored results are the source's own amounts, so the
     * card starts as never run and offers the run.
     */
    private function copyMonteCarloRun(User $source, User $guest): void
    {
        foreach ($this->rowsOf('fin_monte_carlo_runs', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_monte_carlo_runs', [
                ...$row,
                'user_id' => $guest->id,
                'status' => 'idle',
                'inputs_hash' => null,
                'results' => null,
                'ran_at' => null,
                'error' => null,
            ]);
        }
    }

    private function copySnapshots(User $source, User $guest): void
    {
        foreach ($this->rowsOf('fin_snapshots', 'user_id', [$source->id]) as $row) {
            $this->insert('fin_snapshots', [
                ...$row,
                'user_id' => $guest->id,
                'assets' => $this->money($row['assets']),
                'liabilities' => $this->money($row['liabilities']),
                'net_worth' => $this->money($row['net_worth']),
                'projection' => $this->moneyList($row['projection']),
                'note' => null,
            ]);
        }
    }

    /**
     * What a holding is called on the guest's side: its type, or for a
     * retirement account its tax treatment and plan ("Roth IRA").
     *
     * @param  array<string, mixed>  $row
     */
    private function holdingLabel(array $row): string
    {
        if ($row['type'] === 'retirement') {
            $taxType = $row['tax_type'] ? config("finance.retirement_tax_types.{$row['tax_type']}.label") : null;
            $plan = $row['plan_type'] ? config("finance.retirement_plans.{$row['plan_type']}.label") : null;

            return trim(($taxType ?? '').' '.($plan ?? 'retirement account'));
        }

        return config("finance.holding_types.{$row['type']}.label") ?? 'Account';
    }

    /**
     * A generic name, unique within its scope: the label alone the first time
     * and numbered after, or numbered from the first with `$always`.
     */
    private function numbered(string $scope, string $label, bool $always = false): string
    {
        $count = $this->named[$scope][$label] = ($this->named[$scope][$label] ?? 0) + 1;

        if ($always || $count > 1) {
            return "{$label} {$count}";
        }

        return $label;
    }

    private function money(mixed $amount): ?float
    {
        if ($amount === null) {
            return null;
        }

        return round((float) $amount * $this->scale, 2);
    }

    /**
     * A JSON list or map of amounts — a scenario's pinned years, a snapshot's
     * projection — with each one scaled.
     */
    private function moneyList(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        return json_encode(array_map(fn (mixed $amount): ?float => $this->money($amount), (array) json_decode($json, true)));
    }

    /**
     * A conversion strategy's years set by hand: what to convert and what to
     * withhold, by year, either of which may be unset.
     */
    private function conversionOverrides(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        return json_encode(array_map(
            fn (array $year): array => array_map(fn (mixed $amount): ?float => $this->money($amount), $year),
            (array) json_decode($json, true),
        ));
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function mapped(array $ids, mixed $oldId): ?int
    {
        if ($oldId === null) {
            return null;
        }

        return $ids[$oldId];
    }

    /**
     * @param  list<int>  $values
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $table, string $column, array $values): array
    {
        return DB::table($table)->whereIn($column, $values)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(string $table, array $row): int
    {
        unset($row['id']);

        return DB::table($table)->insertGetId($row);
    }
}
