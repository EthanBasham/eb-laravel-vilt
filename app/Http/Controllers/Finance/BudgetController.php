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

        $month = Carbon::createFromFormat('!Y-m', $request->validated('month'));
        $amount = $request->validated('amount');

        /*
         * Found with whereDate rather than through updateOrCreate's attribute
         * match: a `date` column is not stored the same way by every driver
         * (SQLite keeps a time on it), so an equality match on the string
         * misses the existing row and the insert then trips the unique index.
         */
        $actual = Actual::query()->where('flow_id', $flow->id)->whereDate('month', $month)->first();

        if ($amount === null) {
            $actual?->delete();

            return back(fallback: route('finance.budget'));
        }

        if ($actual) {
            $actual->update(['amount' => $amount]);

            return back(fallback: route('finance.budget'));
        }

        Actual::query()->create(['user_id' => $request->user()->id, 'flow_id' => $flow->id, 'month' => $month, 'amount' => $amount]);

        return back(fallback: route('finance.budget'));
    }
}
