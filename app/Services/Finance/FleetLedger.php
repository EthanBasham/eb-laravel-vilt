<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Holding;
use App\Models\Finance\Transfer;

/**
 * Walks the fleet forward a month at a time, keeping what a holding *earned*
 * apart from what was *moved* into or out of it.
 *
 * FleetProjector grows each balance on its own; this adds the money that
 * passes between them. Each month, in this order:
 *
 *  1. Every holding grows: an asset at its rate, a liability by its interest.
 *  2. An asset takes its monthly contribution (until retirement), and a
 *     liability its payment (until nothing is owed).
 *  3. Flows that name an account land: an income is paid in after tax, an
 *     expense is paid out. An account cannot go below nothing, so what it
 *     could not pay is counted as `unfunded` rather than taken.
 *  4. The transfers run, in their order.
 *
 * A year-end pinned by a scenario then replaces whatever the year came to,
 * and the next year carries on from it.
 *
 * Simplifications, on purpose:
 *  - A year's flow lands in twelve equal parts, whenever in the year it falls.
 *  - Tax comes off a routed income at the year's average rate on taxed income
 *    (`$taxRates`), because tax is a property of the year's incomes together
 *    and has no exact share to hang on one of them.
 *  - Contributions and payments still arrive from outside the model, as they
 *    do in FleetProjector. To have one come out of an account instead, set it
 *    to zero and add a fixed transfer.
 *  - The first twelve months are counted as this calendar year, whatever
 *    today's date, to match the flows' own year-by-year figures.
 */
class FleetLedger
{
    /**
     * @param  Collection<int, Holding>  $holdings  Leaves — Fleet::leaves().
     * @param  list<int>  $years  The calendar years to walk, in order.
     * @param  list<array{account_id: int, direction: string, taxable_share: float, amounts: array<int, float>}>  $routed  Each flow that names an account, with what it comes to in each year.
     * @param  Collection<int, Transfer>  $transfers  In the order they run.
     * @param  array<int, array{annual_rate?: float|null, monthly_contribution?: float|null, overrides?: array<int|string, float|int>|null}>  $settings  A scenario's say, keyed by holding id.
     * @param  array<int, float>  $taxRates  The share of a taxed income lost to tax in each year, 0–1.
     * @param  int|null  $retirementYear  Contributions to assets stop from this year.
     * @return array{opening: array{assets: float, liabilities: float, net_worth: float}, holdings: array<int, list<array<string, float|int|bool>>>, totals: list<array{year: int, assets: float, liabilities: float, net_worth: float, unfunded: float}>}
     */
    public function run(Collection $holdings, array $years, array $routed = [], ?Collection $transfers = null, array $settings = [], array $taxRates = [], ?int $retirementYear = null): array
    {
        $values = [];
        $isAsset = [];
        $growth = [];
        $monthly = [];
        $rows = [];

        foreach ($holdings as $holding) {
            $rate = $settings[$holding->id]['annual_rate'] ?? $holding->expected_rate;

            $values[$holding->id] = $holding->value;
            $isAsset[$holding->id] = $holding->is_asset;
            // An asset compounds to its annual rate; a debt accrues a twelfth
            // of its APR a month, as a loan is quoted.
            $growth[$holding->id] = $holding->is_asset ? Amortization::monthlyGrowth($rate) : $rate / 100 / 12;
            $monthly[$holding->id] = $settings[$holding->id]['monthly_contribution'] ?? $holding->monthly_contribution;
            $rows[$holding->id] = [];
        }

        $opening = $this->sides($values, $isAsset);

        // Only what can actually run: a flow or a transfer pointing at a
        // holding that is not being walked is left out rather than guessed at.
        $routed = array_values(array_filter($routed, fn (array $flow): bool => $isAsset[$flow['account_id']] ?? false));
        $transfers = ($transfers ?? collect())->filter(fn (Transfer $transfer): bool => ($isAsset[$transfer->from_holding_id] ?? false)
            && isset($values[$transfer->to_holding_id])
            && $transfer->from_holding_id !== $transfer->to_holding_id)->values();

        $totals = [];

        foreach ($years as $year) {
            $isRetired = $retirementYear !== null && $year >= $retirementYear;
            $taxRate = min(1.0, max(0.0, $taxRates[$year] ?? 0.0));

            $current = [];

            foreach ($values as $id => $value) {
                $current[$id] = ['year' => $year, 'start' => $value, 'growth' => 0.0, 'contributions' => 0.0, 'deposits' => 0.0, 'withdrawals' => 0.0, 'transfers_in' => 0.0, 'transfers_out' => 0.0, 'unfunded' => 0.0, 'adjustment' => 0.0, 'is_pinned' => false];
            }

            for ($month = 1; $month <= 12; $month++) {
                foreach ($values as $id => $value) {
                    $earned = $value * $growth[$id];

                    if ($isAsset[$id]) {
                        // Growth can be a loss, but not below nothing.
                        $earned = max(-$value, $earned);
                        $added = $isRetired ? 0.0 : $monthly[$id];

                        $values[$id] = $value + $earned + $added;
                        $current[$id]['contributions'] += $added;
                    } else {
                        $paid = min($monthly[$id], $value + $earned);

                        $values[$id] = $value + $earned - $paid;
                        $current[$id]['contributions'] += $paid;
                    }

                    $current[$id]['growth'] += $earned;
                }

                foreach ($routed as $flow) {
                    $amount = ($flow['amounts'][$year] ?? 0.0) / 12;
                    $id = $flow['account_id'];

                    if ($amount <= 0) {
                        continue;
                    }

                    if ($flow['direction'] === 'income') {
                        $net = $amount * (1 - $flow['taxable_share'] * $taxRate);

                        $values[$id] += $net;
                        $current[$id]['deposits'] += $net;

                        continue;
                    }

                    $taken = min($amount, $values[$id]);

                    $values[$id] -= $taken;
                    $current[$id]['withdrawals'] += $taken;
                    $current[$id]['unfunded'] += $amount - $taken;
                }

                foreach ($transfers as $transfer) {
                    $from = $transfer->from_holding_id;
                    $to = $transfer->to_holding_id;

                    $moved = min($values[$from], $this->wanted($transfer, $values[$from], $values[$to]));

                    // A debt takes no more than is owed.
                    if (! $isAsset[$to]) {
                        $moved = min($moved, $values[$to]);
                    }

                    if ($moved <= 0) {
                        continue;
                    }

                    $values[$from] -= $moved;
                    $values[$to] += $isAsset[$to] ? $moved : -$moved;
                    $current[$from]['transfers_out'] += $moved;
                    $current[$to]['transfers_in'] += $moved;
                }
            }

            foreach ($values as $id => $value) {
                $pins = $settings[$id]['overrides'] ?? [];

                if (array_key_exists($year, $pins)) {
                    $pinned = max(0.0, (float) $pins[$year]);

                    $current[$id]['adjustment'] = $pinned - $value;
                    $current[$id]['is_pinned'] = true;
                    $values[$id] = $pinned;
                }

                $current[$id]['end'] = $values[$id];
                $rows[$id][] = $current[$id];
            }

            $totals[] = ['year' => $year, ...$this->sides($values, $isAsset), 'unfunded' => array_sum(array_column($current, 'unfunded'))];
        }

        return ['opening' => $opening, 'holdings' => $rows, 'totals' => $totals];
    }

    /**
     * What a transfer sets out to move this month, before the source's
     * balance and a debt's remaining balance limit it.
     */
    private function wanted(Transfer $transfer, float $fromValue, float $toValue): float
    {
        $wanted = match ($transfer->kind) {
            'sweep' => $fromValue - $transfer->keep_balance,
            'top_up' => $transfer->keep_balance - $toValue,
            'fixed' => $transfer->amount ?? 0.0,
            default => 0.0,
        };

        // For a sweep or a top-up the amount is a ceiling on the month.
        if ($transfer->kind !== 'fixed' && $transfer->amount !== null) {
            $wanted = min($wanted, $transfer->amount);
        }

        return max(0.0, $wanted);
    }

    /**
     * @param  array<int, float>  $values
     * @param  array<int, bool>  $isAsset
     * @return array{assets: float, liabilities: float, net_worth: float}
     */
    private function sides(array $values, array $isAsset): array
    {
        $assets = 0.0;
        $liabilities = 0.0;

        foreach ($values as $id => $value) {
            if ($isAsset[$id]) {
                $assets += $value;
            } else {
                $liabilities += $value;
            }
        }

        return ['assets' => $assets, 'liabilities' => $liabilities, 'net_worth' => $assets - $liabilities];
    }
}
