<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveProfileRequest;
use App\Models\Finance\Profile;
use App\Services\Finance\SampleFleet;
use App\Services\Finance\TaxCalculator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profile the tools read, and the two blunt instruments beside it:
 * loading the sample fleet and clearing everything.
 */
class SettingsController extends Controller
{
    public function edit(Request $request, SampleFleet $sample, TaxCalculator $tax): Response
    {
        $profile = Profile::for($request->user());

        return Inertia::render('Settings', [
            'profile' => $profile->props,
            'is_empty' => $sample->isEmptyFor($request->user()),
            // What the state and local fields can be filled in from. Only
            // this page needs them, so they are not shared with every visit.
            'presets' => config('finance.tax.states'),
            'tax' => [
                'year' => (int) config('finance.tax.year'),
                // What is in force, then what is built in for the filing
                // status — the same thing until the profile sets its own.
                'deduction' => $tax->forProfile($profile)->deduction($profile->filing_status),
                'built_in_deduction' => $tax->deduction($profile->filing_status),
                'brackets' => $tax->brackets($profile->filing_status),
                'capital_gains_brackets' => $tax->capitalGainsBrackets($profile),
                // What a W-2 wage pays: the employee's half.
                'fica_rate' => round($profile->se_tax_rate / 2, 3),
                'built_in_capital_gains_brackets' => config("finance.tax.capital_gains_brackets.{$profile->filing_status}"),
            ],
            'irmaa' => [
                'year' => (int) config('finance.irmaa.year'),
                'income_year' => (int) config('finance.irmaa.income_year'),
                'tiers' => config("finance.irmaa.tiers.{$profile->filing_status}"),
            ],
        ]);
    }

    public function update(SaveProfileRequest $request): RedirectResponse
    {
        Profile::query()->updateOrCreate(['user_id' => $request->user()->id], $request->validated());

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
