<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveScenarioFlowRequest;
use App\Http\Requests\Finance\SaveScenarioRatesRequest;
use App\Models\Finance\Flow;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;

/**
 * What a scenario changes about its flows.
 *
 * Neither action flashes a message: both are saved from a control that is
 * dragged or nudged many times over, and the chart moving is the confirmation.
 */
class ScenarioFlowController extends Controller
{
    /**
     * One flow's settings, written whole. A rate of null and no pinned years
     * is the flow as it stands, so the row is removed rather than kept empty.
     * The years the rate starts again from are pinned years, so there are
     * none of those without a pin.
     */
    public function update(SaveScenarioFlowRequest $request, Scenario $scenario, Flow $flow): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()) && $flow->isOwnedBy($request->user()), 404);

        $rate = $request->validated('annual_growth_rate');
        $overrides = collect($request->validated('overrides'))->map(fn (mixed $amount): float => round((float) $amount, 2))->sortKeys()->all();
        $restarts = collect($request->validated('restarts'))->map(fn (mixed $year): int => (int) $year)->sort()->values()->all();

        if ($rate === null && $overrides === []) {
            $scenario->scenarioFlows()->where('flow_id', $flow->id)->delete();

            return back(fallback: route('finance.scenarios.show', $scenario));
        }

        ScenarioFlow::query()->updateOrCreate(
            ['scenario_id' => $scenario->id, 'flow_id' => $flow->id],
            ['annual_growth_rate' => $rate, 'overrides' => $overrides ?: null, 'restarts' => $restarts ?: null],
        );

        return back(fallback: route('finance.scenarios.show', $scenario));
    }

    /**
     * One rate across every income, or every expense. Pinned years are left
     * where they are.
     */
    public function updateRates(SaveScenarioRatesRequest $request, Scenario $scenario): RedirectResponse
    {
        abort_unless($scenario->isOwnedBy($request->user()), 404);

        Flow::query()->onlyOwnedBy($request->user())->where('direction', $request->validated('direction'))->pluck('id')
            ->each(fn (int $flowId) => ScenarioFlow::query()->updateOrCreate(
                ['scenario_id' => $scenario->id, 'flow_id' => $flowId],
                ['annual_growth_rate' => $request->validated('annual_growth_rate')],
            ));

        return back(fallback: route('finance.scenarios.show', $scenario));
    }
}
