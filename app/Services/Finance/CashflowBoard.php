<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Transfer;
use App\Models\User;

/**
 * Income & expenses: every flow, what they come to a month, and the
 * automated transfers between accounts.
 */
class CashflowBoard
{
    public function __construct(
        private Fleet $fleet,
        private TaxCalculator $tax,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $flows = $this->fleet->flows($user);

        return [
            'income' => $flows->where('direction', 'income')->map->props->values(),
            'expenses' => $flows->where('direction', 'expense')->map->props->values(),
            'cashflow' => $this->fleet->cashflow($flows, $this->tax->monthlyRunRateTax($flows, Profile::for($user))),
            'income_by_category' => $this->byCategory($flows->where('direction', 'income')),
            'expenses_by_category' => $this->byCategory($flows->where('direction', 'expense')),
            // What a flow can belong to. Names only, so the fleet is not
            // loaded for it.
            'holdings' => Holding::query()->onlyOwnedBy($user)->onlyTopLevel()->inDefaultOrder()->get(['id', 'name'])->map->only(['id', 'name'])->values(),
            'transfers' => Transfer::query()->onlyOwnedBy($user)->inDefaultOrder()->with(['from.parent', 'to.parent'])->get()->map->props->values(),
        ];
    }

    /**
     * What each category comes to a month right now, largest first. A
     * category with nothing running is left out.
     *
     * @param  Collection<int, Flow>  $flows
     * @return Collection<int, array{label: string, value: float}>
     */
    private function byCategory(Collection $flows): Collection
    {
        return $flows->groupBy('category_label')
            ->map(fn (Collection $group, string $label): array => ['label' => $label, 'value' => round((float) $group->sum->current_monthly_amount, 2)])
            ->filter(fn (array $category): bool => $category['value'] > 0)
            ->sortByDesc('value')
            ->values();
    }
}
