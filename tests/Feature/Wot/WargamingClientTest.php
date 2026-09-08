<?php

use Illuminate\Support\Facades\Http;
use App\Services\Wargaming\WargamingClient;
use App\Services\Wargaming\WargamingException;

beforeEach(function () {
    config(['wargaming.application_id' => 'test-app-id', 'wargaming.realm' => 'na']);
});

it('targets the realm host and always sends the application id', function () {
    Http::fake(['*' => Http::response(['status' => 'ok', 'data' => ['1' => ['nickname' => 'Ethan']]])]);

    app(WargamingClient::class)->accountInfo([1]);

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://api.worldoftanks.com/wot/account/info/')
            && $request['application_id'] === 'test-app-id'
            && $request['account_id'] === '1';
    });
});

it('unwraps the data envelope', function () {
    Http::fake(['*' => Http::response(['status' => 'ok', 'data' => ['1' => ['nickname' => 'Ethan']]])]);

    expect(app(WargamingClient::class)->accountInfo([1]))->toBe(['1' => ['nickname' => 'Ethan']]);
});

/**
 * The single most important behaviour in this client: Wargaming answers HTTP
 * 200 for application-level failures, so a "successful" response proves
 * nothing on its own.
 */
it('throws on the error envelope despite a 200 response', function () {
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'INVALID_IP_ADDRESS', 'value' => '203.0.113.7'],
    ], 200)]);

    expect(fn () => app(WargamingClient::class)->accountInfo([1]))
        ->toThrow(WargamingException::class);
});

it('explains an IP rejection in terms of the fix', function () {
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'INVALID_IP_ADDRESS', 'value' => '203.0.113.7'],
    ])]);

    try {
        app(WargamingClient::class)->accountInfo([1]);
    } catch (WargamingException $e) {
        expect($e->isInvalidIpAddress())->toBeTrue()
            ->and($e->getMessage())->toContain('203.0.113.7')
            ->and($e->getMessage())->toContain('Standalone');
    }
});

it('flags a spent access token as recoverable', function () {
    Http::fake(['*' => Http::response([
        'status' => 'error',
        'error' => ['code' => 407, 'message' => 'INVALID_ACCESS_TOKEN'],
    ])]);

    try {
        app(WargamingClient::class)->accountInfo([1], 'stale-token');
    } catch (WargamingException $e) {
        expect($e->isInvalidAccessToken())->toBeTrue()
            ->and($e->isInvalidIpAddress())->toBeFalse();
    }
});

it('sends the access token only when one is given', function () {
    Http::fake(['*' => Http::response(['status' => 'ok', 'data' => []])]);

    app(WargamingClient::class)->accountInfo([1]);
    Http::assertSent(fn ($request) => ! array_key_exists('access_token', $request->data()));

    app(WargamingClient::class)->accountInfo([1], 'a-token');
    Http::assertSent(fn ($request) => ($request['access_token'] ?? null) === 'a-token');
});

it('refuses to call out with no application id configured', function () {
    config(['wargaming.application_id' => null]);
    Http::fake();

    expect(fn () => app(WargamingClient::class)->accountInfo([1]))
        ->toThrow(WargamingException::class, 'No Wargaming application ID is configured. Set WARGAMING_APPLICATION_ID in .env.');

    Http::assertNothingSent();
});

it('returns the login destination rather than following it', function () {
    Http::fake(['*' => Http::response([
        'status' => 'ok',
        'data' => ['location' => 'https://api.worldoftanks.com/wot/auth/login/redirect/?x=1'],
    ])]);

    expect(app(WargamingClient::class)->loginUrl('https://app.test/wot/connect/callback'))
        ->toBe('https://api.worldoftanks.com/wot/auth/login/redirect/?x=1');

    Http::assertSent(fn ($request) => $request['nofollow'] == 1
        && $request['redirect_uri'] === 'https://app.test/wot/connect/callback');
});
