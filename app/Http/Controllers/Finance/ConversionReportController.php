<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\AddToReportRequest;
use App\Http\Requests\Finance\ReplaceReportRequest;
use App\Models\Finance\ConversionReportEntry;
use App\Models\Finance\ConversionStrategy;

/**
 * What is in the Roth report: its columns, each a conversion strategy on one
 * projection. Nothing here makes, changes or removes a strategy; that is
 * ConversionStrategyController's.
 */
class ConversionReportController extends Controller
{
    /**
     * Puts a strategy in the report on the projections named. Any it is
     * already reported on are left as they are.
     */
    public function store(AddToReportRequest $request): RedirectResponse
    {
        $strategy = ConversionStrategy::query()->onlyOwnedBy($request->user())->findOrFail($request->validated('strategy_id'));

        $already = $strategy->reportEntries()->pluck('scenario_id')->all();
        $new = collect($request->validated('projections'))
            ->map(fn (int|string|null $id): ?int => $id === null ? null : (int) $id)
            ->reject(fn (?int $id): bool => in_array($id, $already, true));

        if ($new->count() > ConversionReportEntry::roomFor($request->user())) {
            $most = (int) config('finance.conversion_comparison.max');

            return back(fallback: route('finance.retirement'))->with('error', "No more than {$most} can be in the report at once. Take some out first.");
        }

        $strategy->addToReport($new->all());

        return back(fallback: route('finance.retirement'));
    }

    /**
     * Replaces the whole report: each strategy named on each projection
     * named, and nothing else.
     */
    public function replace(ReplaceReportRequest $request): RedirectResponse
    {
        ConversionReportEntry::replace($request->user(), $request->validated('strategies'), $request->validated('projections'));

        return back(fallback: route('finance.retirement'));
    }

    /**
     * Takes one column out of the report. Its strategy stays.
     */
    public function destroy(Request $request, ConversionReportEntry $entry): RedirectResponse
    {
        abort_unless($entry->isOwnedBy($request->user()), 404);

        $entry->delete();

        return back(fallback: route('finance.retirement'));
    }

    /**
     * Empties the report. No strategy is removed.
     */
    public function clear(Request $request): RedirectResponse
    {
        ConversionReportEntry::query()->onlyOwnedBy($request->user())->delete();

        return back(fallback: route('finance.retirement'));
    }
}
