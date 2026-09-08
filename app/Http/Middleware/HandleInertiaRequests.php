<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Applied only to the World of Tanks route group, not globally — the rest of
 * the site is server-rendered Blade and has no use for Inertia's headers,
 * asset-version handshake or shared props. See routes/web.php.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * Not Breeze's 'app' view: that one is the Blade + jQuery site shell.
     */
    protected $rootView = 'wot';

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                // Whether this user has linked a Wargaming account, so the Vue
                // side can route between the dashboard and the connect screen
                // without a second request.
                'wot' => $user?->wotAccount ? [
                    'nickname' => $user->wotAccount->nickname,
                    'account_id' => $user->wotAccount->account_id,
                    'is_token_valid' => $user->wotAccount->is_token_valid,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
