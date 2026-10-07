<?php

namespace App\Services\Finance;

use App\Models\Finance\Holding;
use App\Models\User;

/**
 * The page for one holding: what is inside it, what it earns and costs, and
 * what is owed against it.
 */
class HoldingBoard
{
    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Holding $holding): array
    {
        $holding->load(['positions', 'parent', 'children.positions', 'flows.holding', 'flows.account', 'flows.children', 'securedBy', 'securedDebts.positions', 'securedDebts.children.positions']);

        // As Fleet::holdings() does: each account inside is handed its parent
        // and an empty set of children, which its accessors would otherwise
        // go back to the database for, one account at a time.
        $holding->children->each(fn (Holding $child) => $child
            ->setRelation('parent', $holding)
            ->setRelation('children', $holding->newCollection()));

        $debt = (float) $holding->securedDebts->sum->value;
        $income = (float) $holding->flows->where('direction', 'income')->sum->current_monthly_amount;
        $expenses = (float) $holding->flows->where('direction', 'expense')->sum->current_monthly_amount;

        return [
            'holding' => $holding->props,
            'parent' => $holding->parent?->only(['id', 'name', 'type']),
            // The accounts inside a compound holding, largest first.
            'children' => $holding->children->sortByDesc->value->map->props->values(),
            'positions' => $holding->positions->sortByDesc('value')->map->props->values(),
            'flows' => $holding->flows->map->props->values(),
            'secured_by' => $holding->securedBy?->only(['id', 'name']),
            'secured_debts' => $holding->securedDebts->map->props->values(),
            'summary' => [
                'equity' => round($holding->value - $debt, 2),
                'debt' => round($debt, 2),
                'monthly_income' => round($income, 2),
                'monthly_expenses' => round($expenses, 2),
                'monthly_net' => round($income - $expenses, 2),
                // Cash thrown off in a year against what the holding is worth.
                'cash_yield' => $holding->value > 0 ? round(($income - $expenses) * 12 / $holding->value * 100, 2) : null,
            ],
            // What a debt can be secured against: the user's other assets.
            // Names only, so the fleet is not loaded for it.
            'assets' => Holding::query()->onlyOwnedBy($user)->onlyTopLevel()->onlyAssets()->whereKeyNot($holding->id)
                ->inDefaultOrder()->get(['id', 'name'])->map->only(['id', 'name'])->values(),
        ];
    }
}
