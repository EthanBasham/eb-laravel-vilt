<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveScenarioHoldingRequest;
use App\Models\Finance\Holding;
use App\Models\Finance\Scenario;

/**
 * What a scenario changes about its holdings. Like ScenarioFlowController,
 * it flashes nothing: the chart moving is the confirmation.
 */
class ScenarioHoldingController extends Controller
{
    /**
     * One holding's settings, written whole: see Scenario::adjustHolding().
     */
    public function update(SaveScenarioHoldingRequest $request, Scenario $scenario, Holding $holding): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()) && $holding->isOwnedBy($request->user()), 404);

        $scenario->adjustHolding($holding, $request->validated('annual_rate'), $request->validated('monthly_contribution'), $request->validated('overrides'));

        return back(fallback: route('finance.scenarios.show', $scenario));
    }
}
