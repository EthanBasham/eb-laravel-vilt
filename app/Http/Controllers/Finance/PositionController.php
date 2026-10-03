<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SavePositionRequest;
use App\Models\Finance\Holding;
use App\Models\Finance\Position;

/**
 * What sits inside an account. A position has no owner of its own — it is
 * the holding's — so every action here checks the holding.
 */
class PositionController extends Controller
{
    public function store(SavePositionRequest $request, Holding $holding): RedirectResponse
    {
        abort_unless($holding->isOwnedBy($request->user()), 404);

        $holding->positions()->create($request->validated());

        return back(fallback: route('finance.fleet.show', $holding));
    }

    public function update(SavePositionRequest $request, Position $position): RedirectResponse
    {
        abort_unless($position->holding->isOwnedBy($request->user()), 404);

        $position->update($request->validated());

        return back(fallback: route('finance.fleet.show', $position->holding_id));
    }

    public function destroy(Request $request, Position $position): RedirectResponse
    {
        abort_unless($position->holding->isOwnedBy($request->user()), 404);

        $position->delete();

        return back(fallback: route('finance.fleet.show', $position->holding_id));
    }
}
