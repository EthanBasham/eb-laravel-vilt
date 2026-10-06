<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReplaceComparisonRequest;
use App\Http\Requests\Finance\SaveConversionStrategyRequest;
use App\Http\Requests\Finance\StoreStarterStrategiesRequest;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Scenario;

/**
 * The strategies the Retirement Strategizer reports on. Ordinary CRUD: the
 * figures are worked out when the page is rendered, by ConversionBoard.
 *
 * A strategy is either in the report or in the holding area. The page calls
 * it the report — one strategy in it is reported on alone, several are
 * compared — while the code still says `compare` and `is_compared`. A newly
 * made one — built or a starter — joins the report while it has room and
 * goes to the holding area once it has not. A copy lands beside the one it
 * was made from.
 */
class ConversionStrategyController extends Controller
{
    public function store(SaveConversionStrategyRequest $request): RedirectResponse
    {
        $strategy = ConversionStrategy::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'is_compared' => ConversionStrategy::hasRoomToCompare($request->user()),
        ]);

        if ($strategy->is_compared) {
            return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} added.");
        }

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} added to the holding area. The report is full; bring it in from there.");
    }

    /**
     * One strategy of each kind, on every default, so there is something to
     * compare before anything has been decided: for one projection, or —
     * with `every_projection` — a set for each projection the user has saved.
     * With none saved, that is one set on the income and expenses as entered.
     *
     * Each joins the comparison while it has room and goes to the holding
     * area after, like any new strategy; so the first set is compared and
     * the rest wait.
     */
    public function storeStarters(StoreStarterStrategiesRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Null stands for the income and expenses as entered.
        $projections = $request->boolean('every_projection')
            ? Scenario::query()->onlyOwnedBy($user)->inDefaultOrder()->get()->whenEmpty(fn ($none) => $none->push(null))
            : collect([Scenario::query()->find($request->validated('scenario_id'))]);

        $made = DB::transaction(fn () => $projections->flatMap(fn (?Scenario $scenario) => collect(config('finance.conversion_strategies'))->map(fn (array $kind, string $key) => ConversionStrategy::query()->create([
            'user_id' => $user->id,
            'scenario_id' => $scenario?->id,
            'kind' => $key,
            'is_compared' => ConversionStrategy::hasRoomToCompare($user),
            'conversion_amount' => ($kind['amount'] ?? false) ? config('finance.defaults.conversion_amount') : null,
            'heir_income' => config('finance.defaults.heir_income'),
        ]))->values()));

        $held = $made->where('is_compared', false)->count();
        $where = $held > 0 ? " {$held} of them are in the holding area, as the report is full." : '';

        if ($projections->count() > 1) {
            return back(fallback: route('finance.retirement'))->with('success', "{$made->count()} strategies added: one of each kind for each of your {$projections->count()} projections.{$where}");
        }

        $on = $projections->first() ? " on {$projections->first()->name}" : '';

        return back(fallback: route('finance.retirement'))->with('success', "One strategy of each kind added{$on}.{$where}");
    }

    public function update(SaveConversionStrategyRequest $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->update($request->validated());

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} updated.");
    }

    /**
     * Copies a strategy to where it was copied from: the report for one in
     * the report, the holding area for one held. Only a report already at
     * its most sends a copy made there to the holding area instead.
     *
     * The copy's id is flashed as `copied`, which the page opens for editing:
     * a copy is made to be changed.
     */
    public function duplicate(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $isFull = ConversionStrategy::query()->onlyOwnedBy($request->user())->onlyCompared()->count() >= (int) config('finance.conversion_comparison.max');

        $copy = $strategy->replicate()->fill([
            // An unnamed strategy's copy is unnamed too.
            'name' => $strategy->name === null ? null : str("{$strategy->name} copy")->limit(80, '')->toString(),
            'is_compared' => $strategy->is_compared && ! $isFull,
        ]);
        $copy->save();

        $where = $strategy->is_compared && ! $copy->is_compared ? ' The report is full, so the copy is in the holding area.' : '';

        return back(fallback: route('finance.retirement'))
            ->with('success', "{$copy->label} made from {$strategy->label}.{$where}")
            ->with('copied', $copy->id);
    }

    /**
     * Brings a strategy out of the holding area into the comparison.
     */
    public function compare(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $most = (int) config('finance.conversion_comparison.max');

        if (! $strategy->is_compared && ConversionStrategy::query()->onlyOwnedBy($request->user())->onlyCompared()->count() >= $most) {
            return back(fallback: route('finance.retirement'))->with('error', "No more than {$most} strategies can be in the report at once. Move one to the holding area first.");
        }

        $strategy->update(['is_compared' => true]);

        return back(fallback: route('finance.retirement'));
    }

    /**
     * Sends a strategy back to the holding area.
     */
    public function hold(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->update(['is_compared' => false]);

        return back(fallback: route('finance.retirement'));
    }

    /**
     * Replaces the whole comparison: the strategies named are compared, and
     * every other one goes to the holding area.
     */
    public function replaceComparison(ReplaceComparisonRequest $request): RedirectResponse
    {
        $compared = $request->validated('strategies');

        DB::transaction(function () use ($request, $compared): void {
            ConversionStrategy::query()->onlyOwnedBy($request->user())->whereKeyNot($compared)->update(['is_compared' => false]);
            ConversionStrategy::query()->onlyOwnedBy($request->user())->whereKey($compared)->update(['is_compared' => true]);
        });

        return back(fallback: route('finance.retirement'));
    }

    /**
     * Empties the comparison: every strategy goes to the holding area.
     * Nothing is removed.
     */
    public function clearComparison(Request $request): RedirectResponse
    {
        ConversionStrategy::query()->onlyOwnedBy($request->user())->onlyCompared()->update(['is_compared' => false]);

        return back(fallback: route('finance.retirement'));
    }

    public function destroy(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->delete();

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->label} removed.");
    }
}
