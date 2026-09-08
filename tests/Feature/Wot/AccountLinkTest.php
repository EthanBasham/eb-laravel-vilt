<?php

use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\WotAccount;
use Illuminate\Http\Client\ConnectionException;

it('keeps the whole sub-project behind auth', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['wot.dashboard', 'wot.link.create']);

it('sends the player to Wargaming to authorise', function () {
    Http::fake(['*' => Http::response([
        'status' => 'ok',
        'data' => ['location' => 'https://api.worldoftanks.com/wot/auth/login/redirect/?x=1'],
    ])]);

    $this->actingAs(User::factory()->create())
        ->get(route('wot.link.create'))
        ->assertRedirect('https://api.worldoftanks.com/wot/auth/login/redirect/?x=1');
});

it('surfaces an API failure instead of redirecting into nowhere', function () {
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'INVALID_IP_ADDRESS', 'value' => '203.0.113.7'],
    ])]);

    $this->actingAs(User::factory()->create())
        ->get(route('wot.link.create'))
        ->assertRedirect(route('wot.dashboard'))
        ->assertSessionHas('error');
});

it('stores the account on a successful callback', function () {
    $user = User::factory()->create();
    $expiresAt = now()->addWeeks(2)->timestamp;

    $this->actingAs($user)
        ->get(route('wot.link.callback', [
            'status' => 'ok',
            'account_id' => 1005000001,
            'nickname' => 'EthanB',
            'access_token' => 'a-real-token',
            'expires_at' => $expiresAt,
        ]))
        ->assertRedirect(route('wot.dashboard'))
        ->assertSessionHas('success');

    $account = $user->fresh()->wotAccount;

    expect($account->account_id)->toBe(1005000001)
        ->and($account->nickname)->toBe('EthanB')
        ->and($account->access_token)->toBe('a-real-token')
        ->and($account->is_token_valid)->toBeTrue();
});

/**
 * The token arrives as a query parameter — there is no code-for-token exchange
 * in Wargaming's flow — so the callback has to refuse anything incomplete
 * rather than write a row that looks linked but cannot authenticate.
 */
it('refuses a callback that is not a success', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wot.link.callback', ['status' => 'error', 'message' => 'user_denied']))
        ->assertRedirect(route('wot.dashboard'))
        ->assertSessionHas('error');

    expect($user->fresh()->wotAccount)->toBeNull();
});

it('refuses a callback missing any required field', function (array $query) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wot.link.callback', ['status' => 'ok', ...$query]))
        ->assertSessionHasErrors();

    expect($user->fresh()->wotAccount)->toBeNull();
})->with([
    'no token' => [['account_id' => 1, 'nickname' => 'E', 'expires_at' => 99999]],
    'no account id' => [['nickname' => 'E', 'access_token' => 't', 'expires_at' => 99999]],
    'no expiry' => [['account_id' => 1, 'nickname' => 'E', 'access_token' => 't']],
]);

it('re-linking replaces the existing row rather than adding a second', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['nickname' => 'OldName']);

    $this->actingAs($user)->get(route('wot.link.callback', [
        'status' => 'ok',
        'account_id' => 42,
        'nickname' => 'NewName',
        'access_token' => 't',
        'expires_at' => now()->addWeek()->timestamp,
    ]));

    expect(WotAccount::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->wotAccount->nickname)->toBe('NewName');
});

it('revokes the token at Wargaming when disconnecting', function () {
    Http::fake(['*' => Http::response(['status' => 'ok', 'data' => []])]);
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('wot.link.destroy'))->assertRedirect(route('wot.dashboard'));

    expect($user->fresh()->wotAccount)->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/wot/auth/logout/'));
});

it('still disconnects locally when Wargaming cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('down'));
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('wot.link.destroy'));

    expect($user->fresh()->wotAccount)->toBeNull();
});

it('stores the access token encrypted at rest', function () {
    $account = WotAccount::factory()->create(['access_token' => 'plain-text-token']);

    $raw = DB::table('wot_accounts')->where('id', $account->id)->value('access_token');

    expect($raw)->not->toBe('plain-text-token')
        ->and($account->fresh()->access_token)->toBe('plain-text-token');
});

it('drops the account link when the user is deleted', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $user->delete();

    expect(WotAccount::count())->toBe(0);
});
