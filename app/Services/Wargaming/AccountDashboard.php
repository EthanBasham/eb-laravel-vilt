<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Models\WotAccount;
use App\Models\WotVehicle;

/**
 * Assembles everything the dashboard renders for one linked account: the
 * lifetime summary and the per-vehicle garage table.
 *
 * Caching lives here rather than in WargamingClient because this is the layer
 * that knows how stale the data is allowed to be — stats only move when a
 * battle ends, so a few minutes costs nothing and keeps page views well clear
 * of the per-key rate limit.
 */
class AccountDashboard
{
    public function __construct(private readonly WargamingClient $client) {}

    /**
     * @return array{summary: array<string, mixed>, vehicles: list<array<string, mixed>>}
     */
    public function for(WotAccount $account): array
    {
        return [
            'summary' => $this->summary($account),
            'vehicles' => $this->vehicles($account),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WotAccount $account): array
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

        return [
            'battles' => $battles,
            'wins' => $wins,
            'losses' => (int) ($stats['losses'] ?? 0),
            'draws' => $battles - $wins - (int) ($stats['losses'] ?? 0),
            'win_rate' => $this->rate($wins, $battles),
            'survival_rate' => $this->rate((int) ($stats['survived_battles'] ?? 0), $battles),
            'avg_damage' => $this->average((int) ($stats['damage_dealt'] ?? 0), $battles),
            'avg_xp' => $this->average((int) ($stats['xp'] ?? 0), $battles),
            'avg_frags' => $this->average((int) ($stats['frags'] ?? 0), $battles, 2),
            'max_damage' => (int) ($stats['max_damage'] ?? 0),
            'global_rating' => (int) ($profile['global_rating'] ?? 0),
            'last_battle_at' => $this->timestamp($profile['last_battle_time'] ?? null),
            'created_at' => $this->timestamp($profile['created_at'] ?? null),
            // Only present when a valid access token was sent; the UI hides the
            // block entirely rather than rendering zeroes.
            'private' => isset($profile['private']) ? [
                'credits' => (int) ($profile['private']['credits'] ?? 0),
                'gold' => (int) ($profile['private']['gold'] ?? 0),
                'free_xp' => (int) ($profile['private']['free_xp'] ?? 0),
            ] : null,
        ];
    }

    /**
     * Per-vehicle rows, joined against the local encyclopedia copy so each one
     * carries a name and tier rather than a bare tank id.
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

        /** @var Collection<int, WotVehicle> $encyclopedia */
        $encyclopedia = WotVehicle::all()->keyBy('tank_id');

        return collect($rows[(string) $account->account_id] ?? [])
            ->map(function (array $row) use ($encyclopedia): ?array {
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

                return [
                    'tank_id' => $vehicle->tank_id,
                    'name' => $vehicle->name,
                    'short_name' => $vehicle->short_name,
                    'tier' => $vehicle->tier,
                    'nation' => $vehicle->nation,
                    'type' => $vehicle->type,
                    'is_premium' => $vehicle->is_premium,
                    'image_url' => $vehicle->image_url,
                    'battles' => $battles,
                    'wins' => $wins,
                    'win_rate' => $this->rate($wins, $battles),
                    'avg_damage' => $this->average((int) ($stats['damage_dealt'] ?? 0), $battles),
                    'avg_xp' => $this->average((int) ($stats['xp'] ?? 0), $battles),
                    'mastery' => (int) ($row['mark_of_mastery'] ?? 0),
                ];
            })
            ->filter()
            ->sortByDesc('battles')
            ->values()
            ->all();
    }

    private function rate(int $part, int $whole): float
    {
        if ($whole === 0) {
            return 0.0;
        }

        return round($part / $whole * 100, 2);
    }
    private function average(int $total, int $count, int $precision = 0): float
    {
        if ($count === 0) {
            return 0.0;
        }

        return round($total / $count, $precision);
    }
    private function timestamp(mixed $unix): ?string
    {
        if (! $unix) {
            return null;
        }

        return now()->setTimestamp((int) $unix)->toIso8601String();
    }
}
