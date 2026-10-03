<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia for the Financial Fleet route group (/finance) only.
 *
 * A second middleware rather than a branch inside HandleInertiaRequests: that
 * one renders into the World of Tanks root view and shares that sub-project's
 * props (its bookmarks, its linked account), none of which belongs on a
 * finance page. Each island gets its own root view and its own shared data,
 * and removing one is deleting a file rather than untangling a conditional.
 */
class HandleFinanceInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'finance';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['name' => $user->name, 'email' => $user->email] : null,
            ],
            /*
             * The fixed lists every form on the site picks from, straight out
             * of config so the selects and the validation rules cannot
             * disagree. Static, so it costs nothing to send on each visit.
             */
            'lists' => [
                'holding_types' => config('finance.holding_types'),
                'retirement_plans' => config('finance.retirement_plans'),
                'retirement_tax_types' => config('finance.retirement_tax_types'),
                'position_classes' => config('finance.position_classes'),
                'flow_categories' => config('finance.flow_categories'),
                'flow_taxations' => config('finance.flow_taxations'),
                'frequencies' => config('finance.frequencies'),
                'filing_statuses' => config('finance.tax.filing_statuses'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
