<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveTransferRequest;
use App\Models\Finance\Transfer;

/**
 * Automated transfers: the standing instructions that move left-over money
 * between holdings. Ordinary CRUD, listed on the income & expenses page; they
 * are carried out by FleetLedger wherever the fleet is projected.
 */
class TransferController extends Controller
{
    public function store(SaveTransferRequest $request): RedirectResponse
    {
        $transfer = Transfer::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        return back(fallback: route('finance.cashflow'))->with('success', "{$transfer->name} added.");
    }

    public function update(SaveTransferRequest $request, Transfer $transfer): RedirectResponse
    {
        abort_unless($transfer->isOwnedBy($request->user()), 404);

        $transfer->update($request->validated());

        return back(fallback: route('finance.cashflow'))->with('success', "{$transfer->name} updated.");
    }

    public function destroy(Request $request, Transfer $transfer): RedirectResponse
    {
        abort_unless($transfer->isOwnedBy($request->user()), 404);

        $transfer->delete();

        return back(fallback: route('finance.cashflow'))->with('success', "{$transfer->name} removed.");
    }
}
