<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Armada;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\User;

/**
 * Armadas: the fleet in named parts.
 *
 * What is in an armada is whatever points at it — see `armada_key` on Holding
 * and Flow for who follows whom. Income and expenses here are before tax:
 * tax is worked out on a household's incomes together, and has no honest
 * share to hand to one armada.
 */
class ArmadaBoard
{
    public function __construct(
        private Fleet $fleet,
        private ScenarioBoard $scenarios,
    ) {}

    /**
     * The list page: every armada with what it comes to, and whatever is in
     * none of them.
     *
     * @return array<string, mixed>
     */
    public function listFor(User $user): array
    {
        $holdings = $this->fleet->holdings($user);
        $flows = $this->fleet->flows($user);

        $armadas = Armada::query()->onlyOwnedBy($user)->inDefaultOrder()->get();

        return [
            'armadas' => $armadas->map(fn (Armada $armada): array => [
                ...$armada->props,
                ...$this->summary($holdings->where('armada_key', $armada->id), $flows->where('armada_key', $armada->id)),
            ])->values(),
            'unassigned' => $this->members($holdings->whereNull('armada_key'), $flows->whereNull('armada_key')),
            'totals' => $this->fleet->totals($holdings),
            'is_empty' => $holdings->isEmpty() && $flows->isEmpty(),
        ];
    }

    /**
     * One armada's page: what is in it, what it comes to, where it is headed
     * with every flow and holding projected as it stands, and what could
     * still be added to it.
     *
     * @return array<string, mixed>
     */
    public function for(User $user, Armada $armada): array
    {
        $holdings = $this->fleet->holdings($user);
        $flows = $this->fleet->flows($user);
        $profile = Profile::for($user);

        $own = $holdings->where('armada_key', $armada->id);
        $ownFlows = $flows->where('armada_key', $armada->id);

        return [
            'armada' => $armada->props,
            ...$this->summary($own, $ownFlows),
            'assets' => $own->where('side', 'asset')->map->props->values(),
            'liabilities' => $own->where('side', 'liability')->map->props->values(),
            'income' => $ownFlows->where('direction', 'income')->map->props->values(),
            'expenses' => $ownFlows->where('direction', 'expense')->map->props->values(),
            'projection' => $this->projection($user, $armada, $flows, $profile),
            // What is in no armada yet, to be brought into this one.
            'unassigned' => $this->members($holdings->whereNull('armada_key'), $flows->whereNull('armada_key')),
            'others' => Armada::query()->onlyOwnedBy($user)->whereKeyNot($armada->id)->inDefaultOrder()->get()->map->only(['id', 'name'])->values(),
            'holdings' => $holdings->map->only(['id', 'name'])->values(),
        ];
    }

    /**
     * @param  Collection<int, Holding>  $holdings
     * @param  Collection<int, Flow>  $flows
     * @return array<string, mixed>
     */
    private function summary(Collection $holdings, Collection $flows): array
    {
        $income = (float) $flows->where('direction', 'income')->sum->current_monthly_amount;
        $expenses = (float) $flows->where('direction', 'expense')->sum->current_monthly_amount;

        return [
            'totals' => $this->fleet->totals($holdings),
            'cashflow' => [
                'income' => round($income, 2),
                'expenses' => round($expenses, 2),
                'net' => round($income - $expenses, 2),
            ],
            'holdings_count' => $holdings->count(),
            'flows_count' => $flows->count(),
            // The largest few, for the card on the list page.
            'top_holdings' => $holdings->sortByDesc->value->take(4)->map(fn (Holding $holding): array => [
                'id' => $holding->id,
                'name' => $holding->name,
                'side' => $holding->side,
                'value' => $holding->value,
            ])->values(),
        ];
    }

    /**
     * Holdings and flows as the "bring into an armada" lists show them. A
     * flow hung off a holding is left out: it moves when the holding does.
     *
     * @param  Collection<int, Holding>  $holdings
     * @param  Collection<int, Flow>  $flows
     * @return array{holdings: Collection<int, array<string, mixed>>, flows: Collection<int, array<string, mixed>>}
     */
    private function members(Collection $holdings, Collection $flows): array
    {
        return [
            'holdings' => $holdings->map(fn (Holding $holding): array => [
                'id' => $holding->id,
                'name' => $holding->name,
                'side' => $holding->side,
                'type_label' => $holding->type_label,
                'value' => $holding->value,
            ])->values(),
            'flows' => $flows->whereNull('holding_id')->map(fn (Flow $flow): array => [
                'id' => $flow->id,
                'name' => $flow->name,
                'direction' => $flow->direction,
                'category_label' => $flow->category_label,
                'monthly_amount' => round($flow->monthly_amount, 2),
            ])->values(),
        ];
    }

    /**
     * The armada year by year, nothing adjusted: what its flows bring in and
     * spend, and what its holdings are worth.
     *
     * The holdings are walked with the whole fleet, not on their own, so a
     * transfer in from another armada's account still arrives.
     *
     * @param  Collection<int, Flow>  $flows
     * @return list<array<string, int|float>>
     */
    private function projection(User $user, Armada $armada, Collection $flows, Profile $profile): array
    {
        $rows = $this->scenarios->rows($flows, collect(), $profile);
        $holdings = $this->scenarios->holdings($user, $rows, $this->scenarios->totals($rows, $profile), collect(), $profile)['rows']->where('armada_id', $armada->id);
        $own = $rows->where('armada_id', $armada->id);

        $years = [];

        foreach ($own as $row) {
            foreach ($row['series'] as $point) {
                $years[$point['year']] ??= $this->blankYear($point);
                $years[$point['year']][$row['direction'] === 'income' ? 'income' : 'expenses'] += $point['amount'];
            }
        }

        foreach ($holdings as $row) {
            foreach ($row['series'] as $point) {
                $years[$point['year']] ??= $this->blankYear($point);
                $years[$point['year']][$row['side'] === 'asset' ? 'assets' : 'liabilities'] += $point['amount'];
                $years[$point['year']]['growth'] += $row['side'] === 'asset' ? $point['growth'] : -$point['growth'];
            }
        }

        ksort($years);

        return array_values(array_map(fn (array $year): array => [
            ...array_map(fn (int|float $value): int|float => is_float($value) ? round($value, 2) : $value, $year),
            'cashflow' => round($year['income'] - $year['expenses'], 2),
            'net_worth' => round($year['assets'] - $year['liabilities'], 2),
        ], $years));
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, int|float>
     */
    private function blankYear(array $point): array
    {
        return ['year' => $point['year'], 'age' => $point['age'], 'income' => 0.0, 'expenses' => 0.0, 'assets' => 0.0, 'liabilities' => 0.0, 'growth' => 0.0];
    }
}
