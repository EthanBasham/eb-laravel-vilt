<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\ConversionMonteCarlo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Retirement Strategizer: a set of tabs, one tool to a tab. The route
 * only admits the tabs that exist.
 */
class RetirementController extends Controller
{
    public function index(Request $request, ConversionBoard $conversions, ConversionMonteCarlo $monteCarlo, string $tab = 'conversions'): Response
    {
        // A placeholder until the next tool is decided on.
        if ($tab === 'more') {
            return Inertia::render('RetirementMore');
        }

        return Inertia::render('Retirement', [
            ...$conversions->for($request->user()),
            // After the page: even a run small enough for a page load takes
            // a moment, and the strategies are worth seeing first.
            'monte_carlo' => Inertia::defer(fn (): array => $monteCarlo->for($request->user())),
        ]);
    }
}
