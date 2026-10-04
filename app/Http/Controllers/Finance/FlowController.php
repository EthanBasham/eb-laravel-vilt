<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveFlowRequest;
use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\Transfer;
use App\Services\Finance\Fleet;
use App\Services\Finance\TaxCalculator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Income streams and expenses.
 */
class FlowController extends Controller
{
    public function index(Request $request, Fleet $fleet, TaxCalculator $tax): Response
    {
        $flows = $fleet->flows($request->user());

        $byCategory = fn (string $direction): Collection => $flows->where('direction', $direction)
            ->groupBy('category_label')
            ->map(fn (Collection $group, string $label): array => ['label' => $label, 'value' => round((float) $group->sum->current_monthly_amount, 2)])
            ->filter(fn (array $category): bool => $category['value'] > 0)
            ->sortByDesc('value')
            ->values();

        return Inertia::render('Cashflow', [
            'income' => $flows->where('direction', 'income')->map->props->values(),
            'expenses' => $flows->where('direction', 'expense')->map->props->values(),
            'cashflow' => $fleet->cashflow($flows, $tax->monthlyRunRateTax($flows, Profile::for($request->user()))),
            'income_by_category' => $byCategory('income'),
            'expenses_by_category' => $byCategory('expense'),
            'holdings' => $fleet->holdings($request->user())->map->only(['id', 'name'])->values(),
            'transfers' => Transfer::query()->onlyOwnedBy($request->user())->inDefaultOrder()->with(['from.parent', 'to.parent'])->get()->map->props->values(),
        ]);
    }

    public function store(SaveFlowRequest $request): RedirectResponse
    {
        $flow = Flow::query()->create([...$request->flowAttributes(), 'user_id' => $request->user()->id]);

        return back(fallback: route('finance.cashflow'))->with('success', "{$flow->name} added.");
    }

    public function update(SaveFlowRequest $request, Flow $flow): RedirectResponse
    {
        abort_unless($flow->isOwnedBy($request->user()), 404);

        $flow->update($request->flowAttributes());

        return back(fallback: route('finance.cashflow'))->with('success', "{$flow->name} updated.");
    }

    public function destroy(Request $request, Flow $flow): RedirectResponse
    {
        abort_unless($flow->isOwnedBy($request->user()), 404);

        // Its items go with it, by cascade.
        $flow->delete();

        return back(fallback: route('finance.cashflow'))->with('success', "{$flow->name} removed.");
    }
}
