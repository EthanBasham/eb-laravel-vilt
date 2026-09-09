<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotSnapshot;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;

/**
 * Turns the snapshot history into the "last 24 hours / 7 days / 30 days"
 * columns.
 *
 * Every figure here is a difference between two captures, because the Wargaming
 * API only reports lifetime totals. The practical consequence, which the UI has
 * to be honest about: a period is only answerable once a capture exists from
 * before it started. Ask for 30 days on day three and the correct answer is "not
 * enough history yet", not a number computed from the oldest row available —
 * that would silently report three days of play as a month's.
 */
class PeriodStats
{
    /**
     * Windows offered by the dashboard, in days. `null` means "measured in
     * battles rather than time" — see the 1000-battle window below.
     */
    public const WINDOWS = [
        '24h' => 1,
        '3d' => 3,
        '7d' => 7,
        '30d' => 30,
        '60d' => 60,
    ];

    public const BATTLE_WINDOW = 1000;

    public function __construct(private readonly Wn8Calculator $wn8) {}

    /**
     * @return array{history_since: ?string, periods: list<array<string, mixed>>}
     */
    public function for(WotAccount $account): array
    {
        $latest = $account->snapshots()->latest('captured_at')->first();
        $earliest = $account->snapshots()->oldest('captured_at')->first();

        if (! $latest || ! $earliest) {
            return ['history_since' => null, 'periods' => []];
        }

        $periods = [];

        foreach (self::WINDOWS as $label => $days) {
            $periods[] = $this->window(
                $account,
                $latest,
                $label,
                $earliest,
                fn () => $account->snapshots()
                    ->onlyAtOrBefore(now()->subDays($days))
                    ->latest('captured_at')
                    ->first(),
                fn () => $earliest->captured_at->lte(now()->subDays($days)),
            );
        }

        // The battle-count window works the same way, but the cutoff is found by
        // walking back until enough battles have accumulated rather than by date.
        $periods[] = $this->window(
            $account,
            $latest,
            self::BATTLE_WINDOW.' battles',
            $earliest,
            fn () => $account->snapshots()
                ->where('battles', '<=', $latest->battles - self::BATTLE_WINDOW)
                ->latest('battles')
                ->first(),
            fn () => $earliest->battles <= $latest->battles - self::BATTLE_WINDOW,
        );

        return [
            'history_since' => $earliest->captured_at->toIso8601String(),
            'periods' => $periods,
        ];
    }

    /**
     * @param  callable(): ?WotSnapshot  $findBaseline
     * @param  callable(): bool  $hasEnoughHistory
     * @return array<string, mixed>
     */
    private function window(
        WotAccount $account,
        WotSnapshot $latest,
        string $label,
        WotSnapshot $earliest,
        callable $findBaseline,
        callable $hasEnoughHistory,
    ): array {
        if (! $hasEnoughHistory()) {
            return [
                'label' => $label,
                'available' => false,
                'battles' => 0,
            ];
        }

        $baseline = $findBaseline();

        if (! $baseline || $baseline->id === $latest->id) {
            return ['label' => $label, 'available' => false, 'battles' => 0];
        }

        return [
            'label' => $label,
            'available' => true,
            ...$this->difference($account, $baseline, $latest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function difference(WotAccount $account, WotSnapshot $from, WotSnapshot $to): array
    {
        $delta = fn (string $key): int => (int) ($to->statistics[$key] ?? 0) - (int) ($from->statistics[$key] ?? 0);

        $battles = $delta('battles');

        if ($battles <= 0) {
            return ['available' => false, 'battles' => 0];
        }

        $wins = $delta('wins');
        $survived = $delta('survived_battles');
        $damage = $delta('damage_dealt');
        $received = $delta('damage_received');
        $frags = $delta('frags');
        $deaths = $battles - $survived;

        $vehicles = $this->vehicleDeltas($account, $from, $to);

        return [
            'battles' => $battles,
            'win_rate' => $this->rate($wins, $battles),
            'survival_rate' => $this->rate($survived, $battles),
            'avg_damage' => $this->average($damage, $battles),
            // Summed from the three *_assisted_damage totals rather than
            // differencing avg_damage_assisted — that field is already an
            // average over lifetime battles, so subtracting two of them
            // produces a number that means nothing.
            'avg_assist' => $this->average(
                $delta('radio_assisted_damage') + $delta('track_assisted_damage') + $delta('stun_assisted_damage'),
                $battles,
            ),
            'avg_xp' => $this->average($delta('xp'), $battles),
            'avg_frags' => round($battles > 0 ? $frags / $battles : 0, 2),
            // Damage ratio and K/D are the two "am I trading well" numbers, and
            // both divide by something that can legitimately be zero.
            'damage_ratio' => $received > 0 ? round($damage / $received, 2) : null,
            'kd_ratio' => $deaths > 0 ? round($frags / $deaths, 2) : null,
            'avg_tier' => $this->averageTier($vehicles),
            ...$this->wn8->forVehicleRows($vehicles),
        ];
    }

    /**
     * Per-vehicle differences between the two moments, which recent WN8 needs —
     * an account-level delta can't produce it, because expected values differ
     * per vehicle.
     *
     * A vehicle with no row at or before the baseline had not been played then,
     * so its earlier state is zero rather than unknown.
     *
     * @return list<array<string, mixed>>
     */
    private function vehicleDeltas(WotAccount $account, WotSnapshot $from, WotSnapshot $to): array
    {
        $before = $this->vehicleStateAt($account, $from->captured_at);
        $after = $this->vehicleStateAt($account, $to->captured_at);

        $rows = [];

        foreach ($after as $tankId => $current) {
            $earlier = $before->get($tankId);
            $battles = (int) ($current['battles'] ?? 0) - (int) ($earlier['battles'] ?? 0);

            if ($battles <= 0) {
                continue;
            }

            $subtract = fn (string $key): int => (int) ($current[$key] ?? 0) - (int) ($earlier[$key] ?? 0);

            $rows[] = [
                'tank_id' => $tankId,
                'battles' => $battles,
                'wins' => $subtract('wins'),
                'damage_dealt' => $subtract('damage_dealt'),
                'spotted' => $subtract('spotted'),
                'frags' => $subtract('frags'),
                'dropped_capture_points' => $subtract('dropped_capture_points'),
            ];
        }

        return $rows;
    }

    /**
     * Each vehicle's most recent recorded state at or before a moment.
     *
     * One query, then reduced in PHP: rows arrive newest-first, so the first
     * occurrence of a tank id is its state at that moment.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function vehicleStateAt(WotAccount $account, \DateTimeInterface $moment): Collection
    {
        return WotVehicleSnapshot::query()
            ->where('wot_account_id', $account->id)
            ->onlyAtOrBefore($moment)
            ->orderByDesc('captured_at')
            ->get(['tank_id', 'statistics'])
            ->groupBy('tank_id')
            ->map(fn (Collection $group): array => $group->first()->statistics);
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     */
    private function averageTier(array $vehicles): ?float
    {
        $tiers = WotVehicle::whereIn('tank_id', array_column($vehicles, 'tank_id'))
            ->pluck('tier', 'tank_id');

        $weighted = 0;
        $battles = 0;

        foreach ($vehicles as $vehicle) {
            $tier = $tiers[$vehicle['tank_id']] ?? null;

            if (! $tier) {
                continue;
            }

            $weighted += $tier * $vehicle['battles'];
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
}
