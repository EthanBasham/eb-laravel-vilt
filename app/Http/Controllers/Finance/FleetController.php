<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveHoldingRequest;
use App\Models\Finance\Holding;
use App\Services\Finance\Fleet;
use App\Services\Finance\HoldingBoard;
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

    public function show(Request $request, Holding $holding, HoldingBoard $board): Response
    {
        abort_unless($holding->isOwnedBy($request->user()), 404);

        return Inertia::render('Holding', $board->for($request->user(), $holding));
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
