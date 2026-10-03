<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Holding;

/**
 * Walks a set of holdings forward month by month.
 *
 * An asset grows at its expected rate and takes its monthly contribution; a
 * liability accrues interest at its APR and is paid down by its monthly
 * payment until it is gone. That is the whole model — deliberately the
 * simplest thing that gives a believable curve, and the one place to make it
 * cleverer later (contribution limits, payments redirected once a debt
 * clears, and so on).
 */
class FleetProjector
{
    /**
     * Every series is indexed by month, with today at index 0.
     *
     * `$rateShift` moves the growth rate of *investable* assets by that many
     * points, which is how the cautious and optimistic scenarios are drawn; it
     * leaves a savings APY's neighbours — a house, a loan — where they are.
     *
     * @param  Collection<int, Holding>  $holdings
     * @return array{assets: list<float>, liabilities: list<float>, net_worth: list<float>, holdings: array<int, list<float>>, contributed: float}
     */
    public function project(Collection $holdings, int $months, float $rateShift = 0.0, float $extraMonthly = 0.0): array
    {
        $series = [];
        $contributed = 0.0;
        $investableCount = $holdings->filter->is_investable->count();

        foreach ($holdings as $holding) {
            $value = $holding->value;
            $values = [$value];

            if ($holding->is_asset) {
                $rate = $holding->expected_rate + ($holding->is_investable ? $rateShift : 0.0);
                $growth = Amortization::monthlyGrowth($rate);
                // Any extra saving is spread evenly over the investable accounts.
                $deposit = $holding->monthly_contribution + ($holding->is_investable ? $extraMonthly / $investableCount : 0.0);

                for ($month = 1; $month <= $months; $month++) {
                    $value = max(0.0, $value * (1 + $growth) + $deposit);
                    $values[] = $value;
                }

                $contributed += $deposit * $months;
            }

            if (! $holding->is_asset) {
                $monthlyRate = $holding->expected_rate / 100 / 12;

                for ($month = 1; $month <= $months; $month++) {
                    $value = max(0.0, $value * (1 + $monthlyRate) - $holding->monthly_contribution);
                    $values[] = $value;
                }
            }

            $series[$holding->id] = $values;
        }

        $assets = array_fill(0, $months + 1, 0.0);
        $liabilities = array_fill(0, $months + 1, 0.0);

        foreach ($holdings as $holding) {
            foreach ($series[$holding->id] as $month => $value) {
                if ($holding->is_asset) {
                    $assets[$month] += $value;
                } else {
                    $liabilities[$month] += $value;
                }
            }
        }

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'net_worth' => array_map(fn (float $asset, float $liability): float => $asset - $liability, $assets, $liabilities),
            'holdings' => $series,
            'contributed' => $contributed,
        ];
    }

    /**
     * Every twelfth point of a monthly series: today, then each year-end.
     *
     * @param  list<float>  $monthly
     * @return list<float>
     */
    public function yearly(array $monthly): array
    {
        $yearly = [];

        foreach ($monthly as $month => $value) {
            if ($month % 12 === 0) {
                $yearly[] = round($value, 2);
            }
        }

        return $yearly;
    }
}
