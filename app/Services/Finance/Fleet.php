<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\User;

/**
 * Reads a user's fleet — their holdings and flows — the way every tool wants
 * it: loaded once, with the relations the accessors read already attached.
 *
 * Bound as a plain class, not a singleton, so nothing is remembered between
 * requests (or between the requests of one test).
 */
class Fleet
{
    /**
     * The fleet as it is listed: top-level holdings, each carrying the
     * accounts inside it. Totalling these counts every dollar once, because a
     * compound holding's value is its children's.
     *
     * Each child is handed its parent and an empty set of children up front.
     * The accessors read both, and without this every child would go back to
     * the database to learn that it has a parent and no children.
     *
     * @return Collection<int, Holding>
     */
    public function holdings(User $user): Collection
    {
        $holdings = Holding::query()->onlyOwnedBy($user)->onlyTopLevel()
            ->with(['positions', 'children.positions'])
            ->inDefaultOrder()->get();

        $holdings->each(function (Holding $holding): void {
            $holding->setRelation('parent', null);

            $holding->children->each(fn (Holding $child) => $child
                ->setRelation('parent', $holding)
                ->setRelation('children', $holding->newCollection()));
        });

        return $holdings;
    }

    /**
     * The fleet as it is projected: every holding that carries a balance of
     * its own, so a compound holding is replaced by the accounts inside it.
     *
     * The projector grows each balance at its own rate with its own
     * contribution, which only means something for a leaf. Pass it these, and
     * pass the listing to anything that groups or totals.
     *
     * @param  Collection<int, Holding>  $holdings
     * @return Collection<int, Holding>
     */
    public function leaves(Collection $holdings): Collection
    {
        return $holdings->flatMap(fn (Holding $holding): Collection => $holding->is_compound ? $holding->children : collect([$holding]))->values();
    }

    /**
     * @return Collection<int, Flow>
     */
    public function flows(User $user): Collection
    {
        return Flow::query()->onlyOwnedBy($user)->with('holding')->inDefaultOrder()->get();
    }

    /**
     * @param  Collection<int, Holding>  $holdings
     * @return array{assets: float, liabilities: float, net_worth: float}
     */
    public function totals(Collection $holdings): array
    {
        $assets = (float) $holdings->where('side', 'asset')->sum->value;
        $liabilities = (float) $holdings->where('side', 'liability')->sum->value;

        return [
            'assets' => round($assets, 2),
            'liabilities' => round($liabilities, 2),
            'net_worth' => round($assets - $liabilities, 2),
        ];
    }

    /**
     * The monthly run rate of everything coming in and going out right now —
     * flows that have not started, or have ended, are left out.
     *
     * `$monthlyTaxes` is the estimated tax on that income
     * (TaxCalculator::monthlyRunRateTax()). What is left, and the savings
     * rate, are after it.
     *
     * @param  Collection<int, Flow>  $flows
     * @return array{income: float, taxes: float, tax_rate: float, expenses: float, net: float, savings_rate: float}
     */
    public function cashflow(Collection $flows, float $monthlyTaxes = 0.0): array
    {
        $income = (float) $flows->where('direction', 'income')->sum->current_monthly_amount;
        $expenses = (float) $flows->where('direction', 'expense')->sum->current_monthly_amount;

        return [
            'income' => round($income, 2),
            'taxes' => round($monthlyTaxes, 2),
            'tax_rate' => $income > 0 ? round($monthlyTaxes / $income * 100, 1) : 0.0,
            'expenses' => round($expenses, 2),
            'net' => round($income - $monthlyTaxes - $expenses, 2),
            'savings_rate' => $income > 0 ? round(($income - $monthlyTaxes - $expenses) / $income * 100, 1) : 0.0,
        ];
    }
}
