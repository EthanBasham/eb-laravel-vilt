<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\ConversionMonteCarlo;
use App\Services\Finance\SocialSecurityBoard;
use App\Services\Finance\WithdrawalBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Retirement Strategizer: a set of tabs, one tool to a tab. The route
 * only admits the tabs that exist.
 */
class RetirementController extends Controller
{
    public function index(Request $request, ConversionBoard $conversions, ConversionMonteCarlo $monteCarlo, SocialSecurityBoard $socialSecurity, WithdrawalBoard $withdrawals, string $tab = 'conversions'): Response
    {
        if ($tab === 'social-security') {
            return Inertia::render('SocialSecurity', $socialSecurity->for($request->user()));
        }

        if ($tab === 'withdrawals') {
            return Inertia::render('Withdrawals', $withdrawals->for($request->user()));
        }

        return Inertia::render('Retirement', [
            ...$conversions->for($request->user()),
            // After the page: even a run small enough for a page load takes
            // a moment, and the strategies are worth seeing first.
            'monte_carlo' => Inertia::defer(fn (): array => $monteCarlo->for($request->user())),
        ]);
    }
}
