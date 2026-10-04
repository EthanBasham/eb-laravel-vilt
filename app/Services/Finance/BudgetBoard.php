<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Models\Finance\Actual;
use App\Models\Finance\Flow;
use App\Models\User;

/**
 * The Monthly Budget: every flow's planned amount for one month, beside what
 * it actually came to.
 *
 * It is also where household expenses are set up: a "Household expenses"
 * flow is itemised here, and what its items come to is the one figure the
 * income & expenses page and the projections then carry.
 */
class BudgetBoard
{
    public function __construct(private Fleet $fleet) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();

        $actuals = Actual::query()->onlyOwnedBy($user)->whereDate('month', $month)->pluck('amount', 'flow_id');

        $flows = $this->fleet->flows($user);

        $row = function (Flow $flow) use ($month, $actuals): array {
            $planned = round($flow->plannedFor($month), 2);
            $actual = $actuals->has($flow->id) ? (float) $actuals[$flow->id] : null;

            return [
                'id' => $flow->id,
                'parent_id' => $flow->parent_id,
                'name' => $flow->name,
                'holding_name' => $flow->holding?->name,
                'direction' => $flow->direction,
                'category' => $flow->category,
                'category_label' => $flow->category_label,
                'is_essential' => $flow->is_essential,
                // A household expense, itemised or not: listed in its own
                // card rather than among the ordinary expenses.
                'is_household' => $flow->is_itemized || $flow->parent_id !== null,
                'planned' => $planned,
                'actual' => $actual,
                /*
                 * Signed so that positive is always good news: more income
                 * than planned, or less spending. The page colours by the
                 * sign and never has to know which direction a row is.
                 */
                'variance' => $actual === null ? null : round($flow->is_income ? $actual - $planned : $planned - $actual, 2),
            ];
        };

        // Every line that carries an amount of its own: an itemised
        // household expense is its items, never itself.
        $rows = $this->fleet->flowLeaves($flows)->map($row)
            // A flow that neither applies this month nor has anything recorded
            // against it is noise — a pension that starts in 2040.
            ->filter(fn (array $row): bool => $row['planned'] != 0.0 || $row['actual'] !== null)
            ->values();

        $income = $rows->where('direction', 'income');
        $expenses = $rows->where('direction', 'expense');
        $plannedIncome = (float) $income->sum('planned');
        $needs = (float) $expenses->where('is_essential', true)->sum('planned');
        $wants = (float) $expenses->where('is_essential', false)->sum('planned');

        $share = fn (float $amount): float => $plannedIncome > 0 ? round($amount / $plannedIncome * 100, 1) : 0.0;

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'previous' => $month->copy()->subMonth()->format('Y-m'),
            'next' => $month->copy()->addMonth()->format('Y-m'),
            'income' => $income->values()->all(),
            'expenses' => $expenses->where('is_household', false)->values()->all(),
            /*
             * Household expenses are set up here. Each is one line on the
             * income & expenses page and, on this one, either its items —
             * every one, running this month or not, since this is where they
             * are edited — or the single estimate it stands at until it has
             * some. `flow` is what the edit form is filled from.
             */
            'households' => $flows->filter->is_itemized->map(fn (Flow $flow): array => [
                ...$row($flow),
                'flow' => $flow->props,
                'is_itemised' => $flow->is_compound,
                'actual' => $flow->is_compound
                    ? ($flow->children->contains(fn (Flow $item): bool => $actuals->has($item->id)) ? round((float) $flow->children->sum(fn (Flow $item): float => (float) ($actuals[$item->id] ?? 0)), 2) : null)
                    : $row($flow)['actual'],
                'items' => $flow->children->map(fn (Flow $item): array => [...$row($item), 'flow' => $item->props])->values()->all(),
            ])->values()->all(),
            'categories' => $expenses->groupBy('category_label')
                ->map(fn (Collection $group, string $label): array => ['label' => $label, 'value' => round((float) $group->sum('planned'), 2)])
                ->sortByDesc('value')->values()->all(),
            'totals' => [
                'planned_income' => round($plannedIncome, 2),
                'planned_expenses' => round($needs + $wants, 2),
                'planned_net' => round($plannedIncome - $needs - $wants, 2),
                'actual_income' => round((float) $income->sum('actual'), 2),
                'actual_expenses' => round((float) $expenses->sum('actual'), 2),
                'tracked' => $rows->whereNotNull('actual')->count(),
                'rows' => $rows->count(),
            ],
            // The 50 / 30 / 20 guideline, as shares of planned income.
            'split' => [
                ['key' => 'needs', 'label' => 'Needs', 'amount' => round($needs, 2), 'share' => $share($needs), 'target' => 50],
                ['key' => 'wants', 'label' => 'Wants', 'amount' => round($wants, 2), 'share' => $share($wants), 'target' => 30],
                ['key' => 'savings', 'label' => 'Left to save', 'amount' => round($plannedIncome - $needs - $wants, 2), 'share' => $share($plannedIncome - $needs - $wants), 'target' => 20],
            ],
        ];
    }
}
