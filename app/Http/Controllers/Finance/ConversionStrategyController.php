<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PreviewConversionStrategyRequest;
use App\Http\Requests\Finance\SaveConversionStrategyRequest;
use App\Http\Requests\Finance\StoreStarterStrategiesRequest;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Scenario;
use App\Services\Finance\ConversionBoard;

/**
 * The strategies the Retirement Strategizer reports on. Ordinary CRUD: the
 * figures are worked out when the page is rendered, by ConversionBoard.
 *
 * A strategy is settings alone, and is in the report once for each
 * projection it is reported on (ConversionReportEntry). A new one is in no
 * report until it is added to one, and an edit never moves it: what is in
 * the report is ConversionReportController's.
 */
class ConversionStrategyController extends Controller
{
    public function store(SaveConversionStrategyRequest $request): RedirectResponse
    {
        $strategy = ConversionStrategy::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} added.");
    }

    /**
     * One strategy of each kind, on every default, so there is something to
     * compare before anything has been decided: put in the report on one
     * projection, or — with `every_projection` — on each the user has saved.
     * With none saved, that is the income and expenses as entered.
     *
     * A kind the user already has on every default is used rather than made
     * again, and the columns join the report only while it is short of where
     * it stops filling by itself.
     */
    public function storeStarters(StoreStarterStrategiesRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Null stands for the income and expenses as entered.
        $projections = $request->boolean('every_projection')
            ? Scenario::query()->onlyOwnedBy($user)->inDefaultOrder()->get()->whenEmpty(fn ($none) => $none->push(null))
            : collect([Scenario::query()->find($request->validated('scenario_id'))]);

        $added = ConversionStrategy::createStarters($user, $projections)->count();
        $wanted = count(config('finance.conversion_strategies')) * $projections->count();

        $on = $projections->count() > 1
            ? " on each of your {$projections->count()} projections"
            : ($projections->first() ? " on {$projections->first()->name}" : '');

        if ($added < $wanted) {
            return back(fallback: route('finance.retirement'))->with('success', "One strategy of each kind is ready. {$added} of the {$wanted} it comes to{$on} joined the report, which fills no further by itself; add the rest from the strategies above it.");
        }

        return back(fallback: route('finance.retirement'))->with('success', "One strategy of each kind is in the report{$on}.");
    }

    /**
     * What a strategy's settings would convert year by year on one
     * projection, without keeping them: the form's table of years set by
     * hand asks this each time one is changed. Plain JSON, not an Inertia
     * visit.
     */
    public function preview(PreviewConversionStrategyRequest $request, ConversionBoard $board): JsonResponse
    {
        return response()->json(['rows' => $board->preview(
            $request->user(),
            new ConversionStrategy($request->safe()->except('scenario_id')),
            Scenario::query()->find($request->validated('scenario_id')),
        )]);
    }

    public function update(SaveConversionStrategyRequest $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->update($request->validated());

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} updated.");
    }

    /**
     * Copies a strategy, onto the same projections in the report for as long
     * as the report has room.
     *
     * The copy's id is flashed as `copied`, which the page opens for editing:
     * a copy is made to be changed.
     */
    public function duplicate(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $copy = $strategy->duplicate();

        $short = $strategy->reportEntries()->count() - $copy->reportEntries()->count();
        $where = $short > 0 ? ' The report is full, so not every column of it was copied.' : '';

        return back(fallback: route('finance.retirement'))
            ->with('success', "{$copy->label} made from {$strategy->label}.{$where}")
            ->with('copied', $copy->id);
    }

    public function destroy(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->delete();

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} removed.");
    }
}
