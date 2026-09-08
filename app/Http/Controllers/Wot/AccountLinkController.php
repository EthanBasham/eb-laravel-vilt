<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\WotAccount;
use App\Services\Wargaming\WargamingClient;
use App\Services\Wargaming\WargamingException;

/**
 * Wargaming OpenID account linking.
 *
 * The flow, which is not quite standard OpenID Connect:
 *
 *   1. Ask the API for a login URL, passing the address to come back to.
 *      `nofollow=1` makes it hand back the destination as data rather than
 *      issuing a redirect.
 *   2. Send the player there. They authenticate with Wargaming directly; this
 *      application never sees their password.
 *   3. Wargaming redirects back with `status`, and on success `account_id`,
 *      `nickname`, `access_token` and `expires_at` — as plain query parameters.
 *
 * There is no code-for-token exchange step: the token arrives in the URL. That
 * makes the callback the security-sensitive part, which is why it re-checks the
 * status and refuses to trust a partial response.
 */
class AccountLinkController extends Controller
{
    public function create(WargamingClient $client): RedirectResponse
    {
        try {
            return redirect()->away($client->loginUrl(route('wot.link.callback')));
        } catch (WargamingException $e) {
            return redirect()->route('wot.dashboard')->with('error', $e->getMessage());
        }
    }
    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('status') !== 'ok') {
            return redirect()->route('wot.dashboard')->with(
                'error',
                'Wargaming did not authorise the connection: '.$request->string('message')->toString(),
            );
        }

        // Every field is required. A response missing any of them isn't a
        // usable link, and half-writing one would leave a row that looks
        // connected but can't authenticate.
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'nickname' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
            'expires_at' => ['required', 'integer'],
        ]);

        WotAccount::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'account_id' => $validated['account_id'],
                'nickname' => $validated['nickname'],
                'access_token' => $validated['access_token'],
                'access_token_expires_at' => now()->setTimestamp($validated['expires_at']),
            ],
        );

        return redirect()->route('wot.dashboard')
            ->with('success', "Connected as {$validated['nickname']}.");
    }

    /**
     * Unlink, and tell Wargaming to invalidate the token rather than leaving a
     * live credential floating for its remaining two weeks.
     */
    public function destroy(Request $request, WargamingClient $client): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        if ($account) {
            if ($account->is_token_valid) {
                // Best effort: the row goes regardless of whether Wargaming is
                // reachable, or the local state would be stuck on their uptime.
                rescue(fn () => $client->logout($account->access_token), report: false);
            }

            $account->delete();
        }

        return redirect()->route('wot.dashboard')->with('success', 'Disconnected.');
    }
}
