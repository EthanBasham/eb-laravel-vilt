<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveFlowRequest;
use App\Models\Finance\Flow;
use App\Services\Finance\CashflowBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Income streams and expenses.
 */
class FlowController extends Controller
{
    public function index(Request $request, CashflowBoard $board): Response
    {
        return Inertia::render('Cashflow', $board->for($request->user()));
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
