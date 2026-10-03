<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Finance\Snapshot;
use App\Services\Finance\RealityBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projected vs Reality, and the snapshots it is drawn from.
 */
class RealityController extends Controller
{
    public function index(Request $request, RealityBoard $board): Response
    {
        return Inertia::render('Reality', $board->for($request->user()));
    }

    public function store(Request $request, RealityBoard $board): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:120']]);

        $board->capture($request->user(), $validated['note'] ?? null);

        return back(fallback: route('finance.reality'))->with('success', 'Snapshot taken.');
    }

    public function destroy(Request $request, Snapshot $snapshot): RedirectResponse
    {
        abort_unless($snapshot->isOwnedBy($request->user()), 404);

        $snapshot->delete();

        return back(fallback: route('finance.reality'))->with('success', 'Snapshot removed.');
    }
}
