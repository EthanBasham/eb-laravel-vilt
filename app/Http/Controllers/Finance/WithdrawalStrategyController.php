<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveWithdrawalStrategyRequest;
use App\Models\Finance\WithdrawalStrategy;

/**
 * The strategies the withdrawals tab compares. Ordinary CRUD: the figures are
 * worked out when the page is rendered, by WithdrawalBoard.
 */
class WithdrawalStrategyController extends Controller
{
    public function store(SaveWithdrawalStrategyRequest $request): RedirectResponse
    {
        $strategy = WithdrawalStrategy::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        return back(fallback: route('finance.retirement', 'withdrawals'))->with('success', "{$strategy->name} added.");
    }

    /**
     * One strategy for each order, all covering what the projection needs,
     * so there is something to compare before anything has been decided.
     */
    public function storeStarters(Request $request): RedirectResponse
    {
        WithdrawalStrategy::createStarters($request->user());

        return back(fallback: route('finance.retirement', 'withdrawals'))->with('success', 'One strategy for each order added. Edit any of them, or copy one to try a variation.');
    }

    public function update(SaveWithdrawalStrategyRequest $request, WithdrawalStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->update($request->validated());

        return back(fallback: route('finance.retirement', 'withdrawals'))->with('success', "{$strategy->name} updated.");
    }

    public function duplicate(Request $request, WithdrawalStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $copy = $strategy->duplicate();

        return back(fallback: route('finance.retirement', 'withdrawals'))->with('success', "{$copy->name} made from {$strategy->name}.");
    }

    public function destroy(Request $request, WithdrawalStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->delete();

        return back(fallback: route('finance.retirement', 'withdrawals'))->with('success', "{$strategy->name} removed.");
    }
}
