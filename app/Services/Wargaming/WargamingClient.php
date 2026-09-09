<?php

namespace App\Services\Wargaming;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Wargaming public API for World of Tanks.
 *
 * Two things about this API shape the whole class:
 *
 * 1. It answers HTTP 200 for application-level errors and puts the real outcome
 *    in `status`. A successful HTTP response therefore proves nothing, so every
 *    call goes through `get()`, which unwraps the envelope and throws.
 * 2. `application_id` is rate limited per key (20 req/s for a Server-type
 *    application), so callers are expected to cache. This class does not cache
 *    on its own — that belongs to the callers that know how stale is acceptable.
 */
class WargamingClient
{
    public function __construct(
        private readonly ?string $applicationId = null,
        private readonly ?string $baseUrl = null,
    ) {}

    /**
     * Player profile and lifetime statistics.
     *
     * `access_token` is optional: without it the response carries public stats
     * only, with it the `private` block (credits, gold, garage) is included too.
     *
     * @param  list<int>  $accountIds
     * @return array<string, mixed>
     */
    public function accountInfo(array $accountIds, ?string $accessToken = null, ?string $extra = null): array
    {
        return $this->get('/wot/account/info/', array_filter([
            'account_id' => implode(',', $accountIds),
            'access_token' => $accessToken,
            'extra' => $extra,
        ]));
    }

    /**
     * Per-vehicle statistics for one player, keyed by account id.
     *
     * @return array<string, mixed>
     */
    public function tankStats(int $accountId, ?string $accessToken = null): array
    {
        return $this->get('/wot/tanks/stats/', array_filter([
            'account_id' => $accountId,
            'access_token' => $accessToken,
        ]));
    }

    /**
     * Per-vehicle achievements, keyed by account id.
     *
     * This is where Marks of Excellence live (`achievements.marksOnGun`, 0-3)
     * alongside `markOfMastery` and every campaign medal. They are not part of
     * tanks/stats, so the dashboard needs both endpoints.
     *
     * @return array<string, mixed>
     */
    public function tankAchievements(int $accountId, ?string $accessToken = null): array
    {
        return $this->get('/wot/tanks/achievements/', array_filter([
            'account_id' => $accountId,
            'access_token' => $accessToken,
        ]));
    }

    /**
     * One page of the vehicle encyclopedia, keyed by tank id.
     *
     * The endpoint caps `limit` at 100, so a full sync pages through it — see
     * the SyncVehicles command.
     *
     * @return array<string, mixed>
     */
    public function vehicles(int $pageNumber = 1, int $limit = 100): array
    {
        return $this->get('/wot/encyclopedia/vehicles/', [
            'fields' => 'tank_id,name,short_name,tier,nation,type,is_premium,is_gift,images,next_tanks,modules_tree',
            'limit' => $limit,
            'page_no' => $pageNumber,
        ]);
    }

    /**
     * Search for accounts by (partial) nickname.
     *
     * @return array<string, mixed>
     */
    public function searchAccounts(string $nickname, int $limit = 10): array
    {
        return $this->get('/wot/account/list/', [
            'search' => $nickname,
            'limit' => $limit,
        ]);
    }

    /**
     * The URL to send a player to so they can authorise this application.
     *
     * `nofollow=1` makes the API hand back the destination as data instead of
     * issuing a redirect, so the caller stays in control of the response.
     */
    public function loginUrl(string $redirectUri): string
    {
        $response = $this->get('/wot/auth/login/', [
            'redirect_uri' => $redirectUri,
            'nofollow' => 1,
        ]);

        return $response['location'];
    }

    /**
     * Extend an access token that hasn't expired yet, without sending the
     * player back through the login screen.
     *
     * @return array<string, mixed>
     */
    public function prolongateToken(string $accessToken): array
    {
        return $this->post('/wot/auth/prolongate/', ['access_token' => $accessToken]);
    }

    /**
     * Invalidate an access token at Wargaming's end.
     */
    public function logout(string $accessToken): void
    {
        $this->post('/wot/auth/logout/', ['access_token' => $accessToken]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get($path, $this->withCredentials($query)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload = []): array
    {
        return $this->send(fn (PendingRequest $request) => $request->asForm()->post($path, $this->withCredentials($payload)));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function withCredentials(array $parameters): array
    {
        return ['application_id' => $this->applicationId(), ...$parameters];
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function send(callable $send): array
    {
        $applicationId = $this->applicationId();

        if (blank($applicationId)) {
            throw new WargamingException(
                'No Wargaming application ID is configured. Set WARGAMING_APPLICATION_ID in .env.',
            );
        }

        try {
            $response = $send(
                Http::baseUrl($this->baseUrl())
                    ->timeout((int) config('wargaming.timeout'))
                    ->retry((int) config('wargaming.retries'), 200, throw: false)
                    ->acceptJson(),
            );
        } catch (ConnectionException $e) {
            throw new WargamingException('Could not reach the Wargaming API: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new WargamingException("The Wargaming API returned HTTP {$response->status()}.");
        }

        $body = $response->json();

        // The error envelope is the only reliable signal — HTTP status is 200
        // even when the call was rejected.
        if (($body['status'] ?? null) === 'error') {
            $error = $body['error'] ?? [];

            throw new WargamingException(
                $this->describe($error),
                $error['message'] ?? null,
                $error['field'] ?? null,
            );
        }

        return $body['data'] ?? [];
    }

    /**
     * Turn Wargaming's terse error codes into something a human can act on.
     *
     * @param  array<string, mixed>  $error
     */
    private function describe(array $error): string
    {
        $code = $error['message'] ?? 'UNKNOWN_ERROR';
        $value = $error['value'] ?? null;

        return match ($code) {
            'INVALID_IP_ADDRESS' => "This application ID is a Server-type application and only accepts requests from its registered IP addresses. This machine appears as {$value}; add it at https://developers.wargaming.net, or use a Standalone application ID instead.",
            'INVALID_APPLICATION_ID' => 'The Wargaming application ID is not valid for this realm. Application IDs belong to exactly one region.',
            'INVALID_ACCESS_TOKEN', 'ACCESS_TOKEN_EXPIRED' => 'The Wargaming access token has expired or been revoked. Reconnect the account.',
            'REQUEST_LIMIT_EXCEEDED' => 'Hit the Wargaming API rate limit for this application ID.',
            'SOURCE_NOT_AVAILABLE' => 'The Wargaming API is temporarily unavailable.',
            default => "The Wargaming API rejected the request: {$code}".($value ? " ({$value})" : ''),
        };
    }

    private function applicationId(): ?string
    {
        return $this->applicationId ?? config('wargaming.application_id');
    }
    private function baseUrl(): string
    {
        return $this->baseUrl
            ?? config('wargaming.hosts.'.config('wargaming.realm'))
            ?? config('wargaming.hosts.na');
    }
}
