<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveMonteCarloRequest;
use App\Models\Finance\MonteCarloRun;
use App\Services\Finance\ConversionMonteCarlo;

/**
 * The Monte Carlo settings on the conversion tab, and the background runs.
 * A run small enough for a page load needs nothing from here: the page works
 * it out when it is opened.
 */
class MonteCarloController extends Controller
{
    public function update(SaveMonteCarloRequest $request, ConversionMonteCarlo $monteCarlo): RedirectResponse
    {
        $settings = MonteCarloRun::for($request->user())->fill($request->validated());
        $settings->save();

        if ($monteCarlo->queueIfNeeded($request->user(), $settings)) {
            return back(fallback: route('finance.retirement'))->with('success', 'Saved. That is too many to work out as the page loads, so it is running in the background.');
        }

        return back(fallback: route('finance.retirement'))->with('success', 'Market settings saved.');
    }

    /** Runs the current settings in the background, for results that are missing or out of date. */
    public function run(Request $request, ConversionMonteCarlo $monteCarlo): RedirectResponse
    {
        // queue() saves the settings, with the run marked as waiting.
        $monteCarlo->queue(MonteCarloRun::for($request->user()));

        return back(fallback: route('finance.retirement'));
    }

    /** A different set of random markets, to see how much the answer leans on the draw. */
    public function reshuffle(Request $request, ConversionMonteCarlo $monteCarlo): RedirectResponse
    {
        $settings = MonteCarloRun::for($request->user());
        $settings->seed += 1;
        $settings->save();

        $monteCarlo->queueIfNeeded($request->user(), $settings);

        return back(fallback: route('finance.retirement'));
    }
}
