<?php

namespace App\Services\Wargaming;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
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
    /**
     * Fields requested from tanks/stats.
     *
     * The endpoint returns 33 fields per vehicle by default, which for a large
     * garage is ~1.8 MB — most of it never read. This list must stay a superset
     * of two consumers: what AccountDashboard renders per vehicle, and what
     * PeriodStats differences between snapshots. Removing a field here breaks
     * one of them silently, as a zero rather than an error.
     */
    private const TANK_STATS_FIELDS = 'tank_id,mark_of_mastery,'
        .'all.battles,all.wins,all.survived_battles,all.damage_dealt,all.damage_received,'
        .'all.frags,all.spotted,all.dropped_capture_points,all.xp,all.hits_percents,'
        .'all.radio_assisted_damage,all.track_assisted_damage,all.stun_assisted_damage,'
        .'all.avg_damage_blocked,all.battle_avg_xp';

    /**
     * tanks/achievements rejects nested field paths, so the whole achievements
     * object comes back — but dropping `series` and `max_series`, which nothing
     * here reads, still halves the payload.
     */
    private const TANK_ACHIEVEMENT_FIELDS = 'tank_id,achievements';

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
            'fields' => self::TANK_STATS_FIELDS,
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
            'fields' => self::TANK_ACHIEVEMENT_FIELDS,
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
            'fields' => 'tank_id,name,short_name,tier,nation,type,is_premium,images,next_tanks,price_credit,modules_tree,crew',
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
        $this->guardApplicationId();

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

        return $this->unwrap($response);
    }

    /**
     * Fetches the three payloads the dashboard needs, concurrently.
     *
     * They are independent, so issuing them in sequence made a cold page load
     * cost the sum of three round trips rather than the slowest one. Http::pool
     * fires them together; the response handling is identical either way.
     *
     * @return array{info: array<string, mixed>, stats: array<string, mixed>, achievements: array<string, mixed>}
     */
    public function dashboardPayloads(int $accountId, ?string $accessToken = null): array
    {
        $this->guardApplicationId();

        $common = array_filter([
            'application_id' => $this->applicationId(),
            'account_id' => $accountId,
            'access_token' => $accessToken,
        ]);

        $responses = Http::pool(fn (Pool $pool): array => [
            $pool->as('info')->timeout((int) config('wargaming.timeout'))->acceptJson()
                ->get($this->baseUrl().'/wot/account/info/', $common),
            $pool->as('stats')->timeout((int) config('wargaming.timeout'))->acceptJson()
                ->get($this->baseUrl().'/wot/tanks/stats/', [...$common, 'fields' => self::TANK_STATS_FIELDS]),
            $pool->as('achievements')->timeout((int) config('wargaming.timeout'))->acceptJson()
                ->get($this->baseUrl().'/wot/tanks/achievements/', [...$common, 'fields' => self::TANK_ACHIEVEMENT_FIELDS]),
        ]);

        $unwrapped = [];

        foreach (['info', 'stats', 'achievements'] as $key) {
            $response = $responses[$key];

            // A pool hands back the exception itself rather than throwing, so a
            // connection failure has to be re-raised deliberately.
            if ($response instanceof \Throwable) {
                throw new WargamingException("Could not reach the Wargaming API: {$response->getMessage()}");
            }

            $unwrapped[$key] = $this->unwrap($response);
        }

        return $unwrapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
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

    private function guardApplicationId(): void
    {
        if (blank($this->applicationId())) {
            throw new WargamingException(
                'No Wargaming application ID is configured. Set WARGAMING_APPLICATION_ID in .env.',
            );
        }
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
