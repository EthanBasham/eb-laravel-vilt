<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\WotAccount;
use App\Services\Wargaming\AccountDashboard;
use App\Services\Wargaming\WargamingException;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, AccountDashboard $dashboard): Response
    {
        $account = $request->user()->wotAccount;

        // Nothing linked yet — the Vue side renders the connect screen instead
        // of an empty dashboard.
        if (! $account) {
            return Inertia::render('Connect');
        }

        try {
            $data = $dashboard->for($account);
        } catch (WargamingException $e) {
            // A spent token is recoverable by reconnecting, so say so rather
            // than showing a generic failure.
            if ($e->isInvalidAccessToken()) {
                $account->forgetToken();
            }

            return Inertia::render('Dashboard', [
                'account' => $this->accountProps($account),
                'error' => $e->getMessage(),
                'summary' => null,
                'vehicles' => [],
            ]);
        }

        $account->update(['last_synced_at' => now()]);

        return Inertia::render('Dashboard', [
            'account' => $this->accountProps($account),
            'error' => null,
            ...$data,
        ]);
    }

    /**
     * Drops the cached API payloads and returns to the dashboard, which then
     * re-fetches. Useful straight after a session, when the 30-minute cache
     * would otherwise still be showing pre-battle numbers.
     */
    public function refresh(Request $request, AccountDashboard $dashboard): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $dashboard->forget($account);

        return back(fallback: route('wot.dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    private function accountProps(WotAccount $account): array
    {
        return [
            'nickname' => $account->nickname,
            'account_id' => $account->account_id,
            'is_token_valid' => $account->is_token_valid,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
        ];
    }
}
