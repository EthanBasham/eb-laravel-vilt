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
        $vehicles = $this->vehicles($account);

        return [
            'summary' => $this->summary($account, $vehicles),
            'achievements' => $this->achievements($account),
            'vehicles' => $vehicles,
            'history' => $this->periods->for($account),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     * @return array<string, mixed>
     */
    private function summary(WotAccount $account, array $vehicles): array
    {
        $info = Cache::remember(
            "wot:account-info:{$account->account_id}",
            (int) config('wargaming.cache.account_info'),
            fn () => $this->client->accountInfo([$account->account_id], $account->access_token),
        );

        $profile = $info[(string) $account->account_id] ?? [];
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
    private function achievements(WotAccount $account): array
    {
        $rows = Cache::remember(
            "wot:achievements:{$account->account_id}",
            (int) config('wargaming.cache.tank_stats'),
            fn () => $this->client->tankAchievements($account->account_id, $account->access_token),
        );

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
    private function vehicles(WotAccount $account): array
    {
        $rows = Cache::remember(
            "wot:tank-stats:{$account->account_id}",
            (int) config('wargaming.cache.tank_stats'),
            fn () => $this->client->tankStats($account->account_id, $account->access_token),
        );

        $marks = $this->marksByTank($account);

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
     * @return array<int, int>
     */
    private function marksByTank(WotAccount $account): array
    {
        $rows = Cache::remember(
            "wot:achievements:{$account->account_id}",
            (int) config('wargaming.cache.tank_stats'),
            fn () => $this->client->tankAchievements($account->account_id, $account->access_token),
        );

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
