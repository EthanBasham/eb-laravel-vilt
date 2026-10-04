<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveScenarioHoldingRequest;
use App\Models\Finance\Holding;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioHolding;

/**
 * What a scenario changes about its holdings. Like ScenarioFlowController,
 * it flashes nothing: the chart moving is the confirmation.
 */
class ScenarioHoldingController extends Controller
{
    /**
     * One holding's settings, written whole. Nothing set at all is the
     * holding as it stands, so the row is removed rather than kept empty.
     */
    public function update(SaveScenarioHoldingRequest $request, Scenario $scenario, Holding $holding): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()) && $holding->isOwnedBy($request->user()), 404);

        $rate = $request->validated('annual_rate');
        $contribution = $request->validated('monthly_contribution');
        $overrides = collect($request->validated('overrides'))->map(fn (mixed $value): float => round((float) $value, 2))->sortKeys()->all();

        if ($rate === null && $contribution === null && $overrides === []) {
            $scenario->scenarioHoldings()->where('holding_id', $holding->id)->delete();

            return back(fallback: route('finance.scenarios.show', $scenario));
        }

        ScenarioHolding::query()->updateOrCreate(
            ['scenario_id' => $scenario->id, 'holding_id' => $holding->id],
            ['annual_rate' => $rate, 'monthly_contribution' => $contribution, 'overrides' => $overrides ?: null],
        );

        return back(fallback: route('finance.scenarios.show', $scenario));
    }
}
