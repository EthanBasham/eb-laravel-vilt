<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotGrind;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;

/**
 * Turns declared grinds into progress, an earning rate and an estimate.
 *
 * The rate is the interesting part. A vehicle's lifetime average XP is a poor
 * predictor — it includes every battle since the account was new, in a stock
 * configuration, years ago. The snapshot history gives something much better:
 * XP actually earned in that vehicle over the last few weeks. Where enough
 * history exists the estimate uses that; otherwise it falls back to the
 * lifetime average and says so, rather than quietly presenting a worse number
 * as though it were the same thing.
 */
class GrindTracker
{
    /** Days of snapshot history considered "recent" for the earning rate. */
    private const RATE_WINDOW_DAYS = 14;

    /**
     * @param  array<int, array<string, mixed>>  $vehicleStats  current tanks/stats keyed by tank_id
     * @return list<array<string, mixed>>
     */
    public function for(WotAccount $account, array $vehicleStats): array
    {
        $grinds = $account->grinds()->inDefaultOrder()->get();

        if ($grinds->isEmpty()) {
            return [];
        }

        $vehicles = WotVehicle::whereIn('tank_id', $grinds->pluck('tank_id'))->get()->keyBy('tank_id');
        $rates = $this->recentRates($account, $grinds->pluck('tank_id')->all());

        return $grinds->map(function (WotGrind $grind) use ($vehicleStats, $vehicles, $rates): array {
            $stats = $vehicleStats[$grind->tank_id] ?? [];
            $currentXp = (int) ($stats['xp'] ?? 0);
            $lifetimeAvg = (float) ($stats['battle_avg_xp'] ?? 0);

            // Written back so a finished grind records when it finished, not
            // when someone next looked at the page.
            $grind->completeIfReached($currentXp);

            $earned = $grind->earned($currentXp);
            $remaining = $grind->remaining($currentXp);
            $rate = $rates[$grind->tank_id] ?? null;

            // Prefer the recent rate; fall back to lifetime, and label which.
            $perBattle = $rate['xp_per_battle'] ?? ($lifetimeAvg > 0 ? $lifetimeAvg : null);
            $source = $rate ? 'recent' : ($lifetimeAvg > 0 ? 'lifetime' : null);

            $vehicle = $vehicles->get($grind->tank_id);

            return [
                'id' => $grind->id,
                'tank_id' => $grind->tank_id,
                'tank_name' => $vehicle?->name ?? "Tank {$grind->tank_id}",
                'tank_tier' => $vehicle?->tier,
                'target_type' => $grind->target_type,
                'target_name' => $grind->target_name,
                'target_xp' => $grind->target_xp,
                'earned_xp' => $earned,
                'remaining_xp' => $remaining,
                'progress' => $grind->target_xp > 0
                    ? round(min(100, $earned / $grind->target_xp * 100), 1)
                    : 0.0,
                'is_complete' => $grind->is_complete,
                'started_at' => $grind->started_at->toIso8601String(),
                'completed_at' => $grind->completed_at?->toIso8601String(),
                'xp_per_battle' => $perBattle ? round($perBattle) : null,
                'rate_source' => $source,
                'battles_remaining' => $perBattle > 0 ? (int) ceil($remaining / $perBattle) : null,
                'days_remaining' => $this->daysRemaining($remaining, $rate),
                'battles_since_start' => $rate['battles'] ?? null,
            ];
        })->all();
    }

    /**
     * XP and battles earned per vehicle over the recent window, derived from
     * the snapshot history.
     *
     * @param  list<int>  $tankIds
     * @return array<int, array{xp: int, battles: int, xp_per_battle: float, xp_per_day: float, days: float}>
     */
    private function recentRates(WotAccount $account, array $tankIds): array
    {
        $since = now()->subDays(self::RATE_WINDOW_DAYS);

        // Every snapshot for these vehicles from the window's start onwards,
        // plus the last one before it — that earlier row is the baseline the
        // window is measured from.
        $rows = WotVehicleSnapshot::query()
            ->where('wot_account_id', $account->id)
            ->whereIn('tank_id', $tankIds)
            ->orderBy('captured_at')
            // `id` is selected deliberately: without the key, every model
            // hydrates with a null primary key and Model::is() then reports two
            // different rows as the same one.
            ->get(['id', 'tank_id', 'captured_at', 'battles', 'statistics']);

        $rates = [];

        foreach ($rows->groupBy('tank_id') as $tankId => $group) {
            /** @var Collection<int, WotVehicleSnapshot> $group */
            $latest = $group->last();

            // The most recent capture at or before the window opened. When the
            // history doesn't reach back that far, the earliest capture we have
            // is the baseline instead — NOT zero. A snapshot's `xp` is the
            // vehicle's lifetime total, so subtracting zero would count years of
            // play as if it happened inside the window.
            $baseline = $group->last(fn (WotVehicleSnapshot $row): bool => $row->captured_at->lte($since))
                ?? $group->first();

            // One capture is a point, not an interval; there is nothing to
            // measure a rate over yet.
            if ($baseline->is($latest)) {
                continue;
            }

            $xp = (int) ($latest->statistics['xp'] ?? 0) - (int) ($baseline->statistics['xp'] ?? 0);
            $battles = $latest->battles - $baseline->battles;

            if ($battles <= 0 || $xp <= 0) {
                continue;
            }

            // Elapsed time is measured between the two captures rather than
            // assumed to be the full window — a vehicle first played three days
            // ago should not have its rate divided across fourteen.
            $days = max($baseline->captured_at->diffInDays($latest->captured_at, true), 1 / 24);

            $rates[(int) $tankId] = [
                'xp' => $xp,
                'battles' => $battles,
                'xp_per_battle' => $xp / $battles,
                'xp_per_day' => $xp / $days,
                'days' => $days,
            ];
        }

        return $rates;
    }

    /**
     * @param  array{xp_per_day: float}|null  $rate
     */
    private function daysRemaining(int $remaining, ?array $rate): ?float
    {
        if ($remaining === 0) {
            return 0.0;
        }

        // Only ever projected from an observed rate. There is no honest way to
        // turn a lifetime average into "days", because it says nothing about
        // how often the vehicle is currently played.
        if (! $rate || ($rate['xp_per_day'] ?? 0) <= 0) {
            return null;
        }

        return round($remaining / $rate['xp_per_day'], 1);
    }

    /**
     * Grindable targets for the vehicles this player owns: the next tanks in
     * each line, and any non-default modules.
     *
     * @param  array<int, array<string, mixed>>  $vehicleStats
     * @return list<array<string, mixed>>
     */
    public function availableTargets(array $vehicleStats): array
    {
        $owned = WotVehicle::whereIn('tank_id', array_keys($vehicleStats))->inDefaultOrder()->get();
        $names = WotVehicle::whereIn('tank_id', $owned->pluck('next_tanks')
            ->filter()
            ->flatMap(fn (array $next): array => array_keys($next))
            ->unique()
            ->all())
            ->pluck('name', 'tank_id');

        return $owned->map(function (WotVehicle $vehicle) use ($names): ?array {
            $targets = [];

            foreach ($vehicle->next_tanks ?? [] as $tankId => $cost) {
                $targets[] = [
                    'type' => WotGrind::TARGET_TANK,
                    'id' => (int) $tankId,
                    'name' => $names[(int) $tankId] ?? "Tank {$tankId}",
                    'xp' => (int) $cost,
                ];
            }

            foreach ($vehicle->modules_tree ?? [] as $module) {
                // Default modules come fitted; there is nothing to grind.
                if ($module['is_default'] ?? false) {
                    continue;
                }

                $targets[] = [
                    'type' => WotGrind::TARGET_MODULE,
                    'id' => (int) $module['module_id'],
                    'name' => $module['name'],
                    'xp' => (int) $module['price_xp'],
                ];
            }

            if ($targets === []) {
                return null;
            }

            usort($targets, fn (array $a, array $b): int => $a['xp'] <=> $b['xp']);

            return [
                'tank_id' => $vehicle->tank_id,
                'name' => $vehicle->name,
                'tier' => $vehicle->tier,
                'targets' => $targets,
            ];
        })->filter()->values()->all();
    }
}
