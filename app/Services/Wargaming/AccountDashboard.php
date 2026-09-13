<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Models\WotAccount;
use App\Models\WotVehicle;

/**
 * Assembles everything the dashboard renders for one linked account: the
 * lifetime summary, the WN8 rating, the achievement totals, the period
 * breakdown, and the per-vehicle garage table.
 *
 * Caching lives here rather than in WargamingClient because this is the layer
 * that knows how stale the data is allowed to be — stats only move when a
 * battle ends, so a few minutes costs nothing and keeps page views well clear
 * of the per-key rate limit.
 */
class AccountDashboard
{
    /**
     * Private balances already looked up this request, null answers included.
     *
     * @var array<int, array{credits: ?int, free_xp: ?int}|null>
     */
    private array $balances = [];

    public function __construct(
        private readonly WargamingClient $client,
        private readonly Wn8Calculator $wn8,
        private readonly PeriodStats $periods,
    ) {}

    /**
     * @return array{summary: array<string, mixed>, achievements: array<string, mixed>, vehicles: list<array<string, mixed>>, history: array<string, mixed>}
     */
    public function for(WotAccount $account): array
    {
        $payloads = $this->payloads($account);
        $vehicles = $this->vehicles($account, $payloads);

        return [
            'summary' => $this->summary($account, $payloads, $vehicles),
            'achievements' => $this->achievements($account, $payloads),
            'vehicles' => $vehicles,
            'history' => $this->periods->for($account),
        ];
    }

    /**
     * The three API payloads the page needs, fetched together and cached as one
     * entry.
     *
     * One entry rather than three because they are always wanted together, and
     * because the cache store is the database — three writes of freshly
     * serialised JSON per cold load was itself a measurable cost, on top of
     * three sequential round trips.
     *
     * @return array{info: array<string, mixed>, stats: array<string, mixed>, achievements: array<string, mixed>}
     */
    private function payloads(WotAccount $account): array
    {
        return Cache::remember(
            "wot:payloads:{$account->account_id}",
            (int) config('wargaming.cache.dashboard'),
            fn () => $this->client->dashboardPayloads($account->account_id, $account->access_token),
        );
    }

    /**
     * Drops the cached payloads so the next render re-fetches from Wargaming.
     */
    public function forget(WotAccount $account): void
    {
        Cache::forget("wot:payloads:{$account->account_id}");
        // freeXp()'s own copy of account/info, so Refresh moves the Grinding
        // page's balance too rather than leaving it on the old figure.
        Cache::forget("wot:account-info:{$account->account_id}");
    }

    /**
     * The account's Free XP balance, or null where Wargaming will not say.
     *
     * Null means "not known", and the Grinding card shows planned alone for it
     * rather than planned over a zero nobody reported. See privateBalances().
     */
    public function freeXp(WotAccount $account): ?int
    {
        return $this->privateBalances($account)['free_xp'] ?? null;
    }

    /**
     * The account's credit balance, or null where Wargaming will not say.
     *
     * The same lookup as freeXp(), and the same meaning of null: the Grinding
     * card shows credits needed alone rather than under a balance nobody
     * reported.
     */
    public function credits(WotAccount $account): ?int
    {
        return $this->privateBalances($account)['credits'] ?? null;
    }

    /**
     * The balances in account/info's private block, or null where Wargaming
     * will not say.
     *
     * Remembered per account for the life of this instance, null answers
     * included: the Grinding page reads two figures from one reply, and a cold
     * cache — or a refusal, which is never cached — must not become one request
     * per figure.
     *
     * @return array{credits: ?int, free_xp: ?int}|null
     */
    private function privateBalances(WotAccount $account): ?array
    {
        if (! array_key_exists($account->id, $this->balances)) {
            $this->balances[$account->id] = $this->lookUpPrivateBalances($account);
        }

        return $this->balances[$account->id];
    }

    /**
     * Fetches the private block, as cheaply as it can be had.
     *
     * The block only comes back with a valid token, so without one this answers
     * null at once and asks nothing.
     *
     * A warm copy of this class's own payloads already holds account/info, so
     * that is read first. Only when it is cold is account/info fetched, on its
     * own: the dashboard's full fetch is dominated by tanks/stats, which the
     * Grinding page has no use for and should not pay for.
     *
     * A refusal is null rather than an error. This feeds figures on a card, and
     * Wargaming being unavailable is no reason for the board beneath them to
     * fail. Refusals are not cached, so the next render asks again.
     *
     * @return array{credits: ?int, free_xp: ?int}|null
     */
    private function lookUpPrivateBalances(WotAccount $account): ?array
    {
        if (! $account->is_token_valid) {
            return null;
        }

        $payloads = Cache::get("wot:payloads:{$account->account_id}");

        if (is_array($payloads)) {
            return $this->balancesFrom((array) ($payloads['info'] ?? []), $account);
        }

        try {
            $info = Cache::remember(
                "wot:account-info:{$account->account_id}",
                (int) config('wargaming.cache.dashboard'),
                fn (): array => $this->client->accountInfo([$account->account_id], $account->access_token),
            );
        } catch (WargamingException) {
            return null;
        }

        return $this->balancesFrom($info, $account);
    }

    /**
     * @param  array<string, mixed>  $info  account/info's data, keyed by account id
     * @return array{credits: ?int, free_xp: ?int}|null
     */
    private function balancesFrom(array $info, WotAccount $account): ?array
    {
        $private = $info[(string) $account->account_id]['private'] ?? null;

        // Each figure is null on its own if missing, rather than zero: an
        // absent key is Wargaming not saying, not a balance of nothing.
        if (is_array($private)) {
            return [
                'credits' => isset($private['credits']) ? (int) $private['credits'] : null,
                'free_xp' => isset($private['free_xp']) ? (int) $private['free_xp'] : null,
            ];
        }

        // No private block is Wargaming declining to say.
        return null;
    }

    /**
     * @param  array<string, mixed>  $payloads
     * @param  list<array<string, mixed>>  $vehicles
     * @return array<string, mixed>
     */
    private function summary(WotAccount $account, array $payloads, array $vehicles): array
    {
        $profile = $payloads['info'][(string) $account->account_id] ?? [];
        $stats = $profile['statistics']['all'] ?? [];

        $battles = (int) ($stats['battles'] ?? 0);
        $wins = (int) ($stats['wins'] ?? 0);
        $survived = (int) ($stats['survived_battles'] ?? 0);
        $damage = (int) ($stats['damage_dealt'] ?? 0);
        $received = (int) ($stats['damage_received'] ?? 0);
        $frags = (int) ($stats['frags'] ?? 0);
        $deaths = $battles - $survived;

        // WN8 is computed from the per-vehicle rows rather than account totals,
        // because expected values are per vehicle.
        $rating = $this->wn8->forVehicleRows($vehicles);

        return [
            'battles' => $battles,
            'wins' => $wins,
            'losses' => (int) ($stats['losses'] ?? 0),
            'draws' => (int) ($stats['draws'] ?? 0),
            'win_rate' => $this->rate($wins, $battles),
            'survival_rate' => $this->rate($survived, $battles),
            'avg_damage' => $this->average($damage, $battles),
            'avg_assist' => $this->average(
                (int) ($stats['radio_assisted_damage'] ?? 0)
                + (int) ($stats['track_assisted_damage'] ?? 0)
                + (int) ($stats['stun_assisted_damage'] ?? 0),
                $battles,
            ),
            'avg_blocked' => round((float) ($stats['avg_damage_blocked'] ?? 0), 0),
            'avg_xp' => $this->average((int) ($stats['xp'] ?? 0), $battles),
            'avg_frags' => round($battles > 0 ? $frags / $battles : 0, 2),
            'damage_ratio' => $received > 0 ? round($damage / $received, 2) : null,
            'kd_ratio' => $deaths > 0 ? round($frags / $deaths, 2) : null,
            'accuracy' => round((float) ($stats['hits_percents'] ?? 0), 2),
            'avg_tier' => $this->averageTier($vehicles),
            'max_damage' => (int) ($stats['max_damage'] ?? 0),
            'max_frags' => (int) ($stats['max_frags'] ?? 0),
            'max_xp' => (int) ($stats['max_xp'] ?? 0),
            'global_rating' => (int) ($profile['global_rating'] ?? 0),
            'wn8' => $rating['wn8'],
            'wn8_band' => Wn8Calculator::band($rating['wn8']),
            // Surfaced so the rating can be honest about its own coverage —
            // XVM has no expected values for some newer vehicles.
            'wn8_unrated_battles' => $rating['unrated_battles'],
            'last_battle_at' => $this->timestamp($profile['last_battle_time'] ?? null),
            'created_at' => $this->timestamp($profile['created_at'] ?? null),
            'private' => isset($profile['private']) ? [
                'credits' => (int) ($profile['private']['credits'] ?? 0),
                'gold' => (int) ($profile['private']['gold'] ?? 0),
                'free_xp' => (int) ($profile['private']['free_xp'] ?? 0),
            ] : null,
        ];
    }

    /**
     * Marks of Excellence and mastery badges, counted across the garage.
     *
     * These come from tanks/achievements, a different endpoint from
     * tanks/stats — MoE in particular is not part of the statistics payload.
     *
     * @return array<string, mixed>
     */
    private function achievements(WotAccount $account, array $payloads): array
    {
        $rows = $payloads['achievements'];

        $marks = [1 => 0, 2 => 0, 3 => 0];
        $mastery = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        foreach ($rows[(string) $account->account_id] ?? [] as $row) {
            $earned = $row['achievements'] ?? [];

            $onGun = (int) ($earned['marksOnGun'] ?? 0);
            $badge = (int) ($earned['markOfMastery'] ?? 0);

            if (isset($marks[$onGun])) {
                $marks[$onGun]++;
            }

            if (isset($mastery[$badge])) {
                $mastery[$badge]++;
            }
        }

        return [
            'marks_of_excellence' => ['one' => $marks[1], 'two' => $marks[2], 'three' => $marks[3]],
            'mastery' => ['third' => $mastery[1], 'second' => $mastery[2], 'first' => $mastery[3], 'ace' => $mastery[4]],
        ];
    }

    /**
     * Per-vehicle rows, joined against the local encyclopedia copy so each one
     * carries a name and tier rather than a bare tank id, and scored
     * individually for WN8.
     *
     * @return list<array<string, mixed>>
     */
    private function vehicles(WotAccount $account, array $payloads): array
    {
        $rows = $payloads['stats'];

        $marks = $this->marksByTank($account, $payloads);

        /** @var Collection<int, WotVehicle> $encyclopedia */
        $encyclopedia = WotVehicle::all()->keyBy('tank_id');

        return collect($rows[(string) $account->account_id] ?? [])
            ->map(function (array $row) use ($encyclopedia, $marks): ?array {
                $vehicle = $encyclopedia->get($row['tank_id'] ?? null);

                // A tank the local encyclopedia doesn't know about — usually a
                // vehicle added in a patch since the last `wot:sync-vehicles`.
                // Dropped rather than rendered as a nameless row.
                if (! $vehicle) {
                    return null;
                }

                $stats = $row['all'] ?? [];
                $battles = (int) ($stats['battles'] ?? 0);
                $wins = (int) ($stats['wins'] ?? 0);
                $survived = (int) ($stats['survived_battles'] ?? 0);
                $damage = (int) ($stats['damage_dealt'] ?? 0);
                $received = (int) ($stats['damage_received'] ?? 0);
                $frags = (int) ($stats['frags'] ?? 0);
                $deaths = $battles - $survived;

                $scoring = [
                    'tank_id' => $vehicle->tank_id,
                    'battles' => $battles,
                    'wins' => $wins,
                    'damage_dealt' => $damage,
                    'spotted' => (int) ($stats['spotted'] ?? 0),
                    'frags' => $frags,
                    'dropped_capture_points' => (int) ($stats['dropped_capture_points'] ?? 0),
                ];

                $wn8 = $this->wn8->forVehicle($scoring);

                return [
                    ...$scoring,
                    'name' => $vehicle->name,
                    'tier' => $vehicle->tier,
                    'nation' => $vehicle->nation,
                    'type' => $vehicle->type,
                    'is_premium' => $vehicle->is_premium,
                    'win_rate' => $this->rate($wins, $battles),
                    'survival_rate' => $this->rate($survived, $battles),
                    'avg_damage' => $this->average($damage, $battles),
                    'avg_assist' => $this->average(
                        (int) ($stats['radio_assisted_damage'] ?? 0)
                        + (int) ($stats['track_assisted_damage'] ?? 0)
                        + (int) ($stats['stun_assisted_damage'] ?? 0),
                        $battles,
                    ),
                    'avg_blocked' => round((float) ($stats['avg_damage_blocked'] ?? 0), 0),
                    'avg_xp' => $this->average((int) ($stats['xp'] ?? 0), $battles),
                    'damage_ratio' => $received > 0 ? round($damage / $received, 2) : null,
                    'kd_ratio' => $deaths > 0 ? round($frags / $deaths, 2) : null,
                    'accuracy' => round((float) ($stats['hits_percents'] ?? 0), 2),
                    'wn8' => $wn8,
                    'wn8_band' => Wn8Calculator::band($wn8),
                    'mastery' => (int) ($row['mark_of_mastery'] ?? 0),
                    'marks' => $marks[$vehicle->tank_id] ?? 0,
                ];
            })
            ->filter()
            ->sortByDesc('battles')
            ->values()
            ->all();
    }

    /**
     * Marks of Excellence per tank, so the garage table can show them per row.
     *
     * @param  array<string, mixed>  $payloads
     * @return array<int, int>
     */
    private function marksByTank(WotAccount $account, array $payloads): array
    {
        $rows = $payloads['achievements'];

        $marks = [];

        foreach ($rows[(string) $account->account_id] ?? [] as $row) {
            $marks[(int) $row['tank_id']] = (int) (($row['achievements'] ?? [])['marksOnGun'] ?? 0);
        }

        return $marks;
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     */
    private function averageTier(array $vehicles): ?float
    {
        $weighted = 0;
        $battles = 0;

        foreach ($vehicles as $vehicle) {
            $weighted += $vehicle['tier'] * $vehicle['battles'];
            $battles += $vehicle['battles'];
        }

        return $battles > 0 ? round($weighted / $battles, 2) : null;
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }
    private function average(int $total, int $count, int $precision = 0): float
    {
        return $count > 0 ? round($total / $count, $precision) : 0.0;
    }
    private function timestamp(mixed $unix): ?string
    {
        return $unix ? now()->setTimestamp((int) $unix)->toIso8601String() : null;
    }
}
