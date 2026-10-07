<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveProfileRequest;
use App\Models\Finance\Profile;
use App\Services\Finance\SampleFleet;
use App\Services\Finance\SettingsBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profile the tools read, and the two blunt instruments beside it:
 * loading the sample fleet and clearing everything.
 */
class SettingsController extends Controller
{
    public function edit(Request $request, SettingsBoard $board): Response
    {
        return Inertia::render('Settings', $board->for($request->user()));
    }

    public function update(SaveProfileRequest $request): RedirectResponse
    {
        Profile::saveFor($request->user(), $request->validated());

        return back(fallback: route('finance.settings'))->with('success', 'Profile saved.');
    }

    public function loadSample(Request $request, SampleFleet $sample): RedirectResponse
    {
        // Only into an empty fleet: loading it twice would double every
        // balance, and loading it over real figures would bury them.
        if (! $sample->isEmptyFor($request->user())) {
            return back(fallback: route('finance.overview'))->with('error', 'The sample fleet only loads into an empty one. Clear yours first.');
        }

        $sample->load($request->user());

        return to_route('finance.overview')->with('success', 'Sample fleet loaded. Clear it from Settings when you are ready for your own.');
    }

    public function clear(Request $request, SampleFleet $sample): RedirectResponse
    {
        $sample->clear($request->user());

        return to_route('finance.overview')->with('success', 'Everything cleared.');
    }
}
