<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveScenarioFlowRequest;
use App\Http\Requests\Finance\SaveScenarioRatesRequest;
use App\Models\Finance\Flow;
use App\Models\Finance\Scenario;

/**
 * What a scenario changes about its flows.
 *
 * Neither action flashes a message: both are saved from a control that is
 * dragged or nudged many times over, and the chart moving is the confirmation.
 */
class ScenarioFlowController extends Controller
{
    /**
     * One flow's settings, written whole: see Scenario::adjustFlow().
     */
    public function update(SaveScenarioFlowRequest $request, Scenario $scenario, Flow $flow): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()) && $flow->isOwnedBy($request->user()), 404);

        $scenario->adjustFlow($flow, $request->validated('annual_growth_rate'), $request->validated('overrides'), $request->validated('restarts') ?? []);

        return back(fallback: route('finance.scenarios.show', $scenario));
    }

    /**
     * One rate across every income, or every expense. Pinned years are left
     * where they are.
     */
    public function updateRates(SaveScenarioRatesRequest $request, Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()), 404);

        $scenario->setRateForDirection($request->validated('direction'), (float) $request->validated('annual_growth_rate'));

        return back(fallback: route('finance.scenarios.show', $scenario));
    }
}
