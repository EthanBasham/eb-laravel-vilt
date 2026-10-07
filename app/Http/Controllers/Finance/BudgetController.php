<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveActualRequest;
use App\Models\Finance\Actual;
use App\Models\Finance\Flow;
use App\Services\Finance\BudgetBoard;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request, BudgetBoard $board): Response
    {
        // An unreadable ?month= shows this month rather than an error page.
        $month = rescue(
            fn (): Carbon => Carbon::createFromFormat('!Y-m', (string) $request->query('month')),
            fn (): Carbon => now()->startOfMonth(),
            report: false,
        );

        return Inertia::render('Budget', $board->for($request->user(), $month));
    }

    public function updateActual(SaveActualRequest $request, Flow $flow): RedirectResponse
    {
        abort_unless($flow->isOwnedBy($request->user()), 404);

        $amount = $request->validated('amount');

        Actual::record($flow, $request->month(), $amount === null ? null : (float) $amount);

        return back(fallback: route('finance.budget'));
    }
}
