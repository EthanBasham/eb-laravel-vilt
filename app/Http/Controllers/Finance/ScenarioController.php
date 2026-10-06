<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveScenarioRequest;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\Finance\ScenarioHolding;
use App\Services\Finance\ScenarioBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projections & scenarios: named sets of assumptions about how each income
 * and expense moves from here to the end of the plan.
 */
class ScenarioController extends Controller
{
    public function index(Request $request, ScenarioBoard $board): Response
    {
        return Inertia::render('Scenarios', $board->listFor($request->user()));
    }

    public function store(SaveScenarioRequest $request): RedirectResponse
    {
        $scenario = Scenario::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        // Straight into it: a scenario is made in order to be adjusted.
        return to_route('finance.scenarios.show', $scenario)->with('success', "{$scenario->name} added. Open an income or an expense to set how it moves.");
    }

    public function show(Request $request, Scenario $scenario, ScenarioBoard $board): Response
    {
        abort_unless($scenario->isOwnedBy($request->user()), 404);

        return Inertia::render('Scenario', $board->for($request->user(), $scenario));
    }

    public function update(SaveScenarioRequest $request, Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()), 404);

        $scenario->update($request->validated());

        return back(fallback: route('finance.scenarios'))->with('success', "{$scenario->name} updated.");
    }

    /**
     * A copy with every rate and pinned year carried over, as the starting
     * point for a variation.
     */
    public function duplicate(Request $request, Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()), 404);

        $copy = DB::transaction(function () use ($scenario): Scenario {
            $copy = $scenario->replicate()->fill(['name' => str("{$scenario->name} copy")->limit(80, '')->toString()]);
            $copy->save();

            $scenario->scenarioFlows->each(fn (ScenarioFlow $settings) => $copy->scenarioFlows()->create($settings->only(['flow_id', 'annual_growth_rate', 'overrides', 'restarts'])));
            $scenario->scenarioHoldings->each(fn (ScenarioHolding $settings) => $copy->scenarioHoldings()->create($settings->only(['holding_id', 'annual_rate', 'monthly_contribution', 'overrides'])));

            return $copy;
        });

        return to_route('finance.scenarios.show', $copy)->with('success', "{$copy->name} made from {$scenario->name}.");
    }

    public function destroy(Request $request, Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()), 404);

        $scenario->delete();

        return to_route('finance.scenarios')->with('success', "{$scenario->name} removed.");
    }
}
