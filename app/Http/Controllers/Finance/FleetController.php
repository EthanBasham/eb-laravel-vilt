<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveHoldingRequest;
use App\Models\Finance\Holding;
use App\Services\Finance\Fleet;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fleet itself: every asset and liability, and the page for one of them.
 */
class FleetController extends Controller
{
    public function index(Request $request, Fleet $fleet): Response
    {
        $holdings = $fleet->holdings($request->user());

        return Inertia::render('Fleet', [
            'assets' => $holdings->where('side', 'asset')->map->props->values(),
            'liabilities' => $holdings->where('side', 'liability')->map->props->values(),
            'totals' => $fleet->totals($holdings),
        ]);
    }

    public function show(Request $request, Holding $holding, Fleet $fleet): Response
    {
        abort_unless($holding->isOwnedBy($request->user()), 404);

        $holding->load(['positions', 'parent', 'children.positions', 'children.children', 'flows.holding', 'securedBy', 'securedDebts.positions']);

        $debt = (float) $holding->securedDebts->sum->value;
        $income = (float) $holding->flows->where('direction', 'income')->sum->current_monthly_amount;
        $expenses = (float) $holding->flows->where('direction', 'expense')->sum->current_monthly_amount;

        return Inertia::render('Holding', [
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
            'assets' => $fleet->holdings($request->user())->where('side', 'asset')->where('id', '!=', $holding->id)
                ->map->only(['id', 'name'])->values(),
        ]);
    }

    public function store(SaveHoldingRequest $request): RedirectResponse
    {
        $holding = Holding::query()->create([...$request->holdingAttributes(), 'user_id' => $request->user()->id]);

        // An account added inside another lands back on the outer one, which
        // is the page it was added from and where it is now listed.
        return to_route('finance.fleet.show', $holding->parent_id ?? $holding)->with('success', "{$holding->name} added to your fleet.");
    }

    public function update(SaveHoldingRequest $request, Holding $holding): RedirectResponse
    {
        abort_unless($holding->isOwnedBy($request->user()), 404);

        $holding->update($request->holdingAttributes());

        return back(fallback: route('finance.fleet'))->with('success', "{$holding->name} updated.");
    }

    public function destroy(Request $request, Holding $holding): RedirectResponse
    {
        abort_unless($holding->isOwnedBy($request->user()), 404);

        $parentId = $holding->parent_id;

        $holding->delete();

        return ($parentId ? to_route('finance.fleet.show', $parentId) : to_route('finance.fleet'))
            ->with('success', "{$holding->name} removed, along with everything inside it.");
    }
}
