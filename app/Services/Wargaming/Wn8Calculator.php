<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotExpectedValue;

/**
 * WN8 — the community rating that measures a player against XVM's expected
 * performance for each vehicle they play, weighted by battles in it.
 *
 * The formula is fixed by the community specification; the constants below are
 * not tuning knobs and should not be adjusted. Expected values come from
 * `wot_expected_values` (see the SyncExpectedValues command).
 *
 * Deliberately works on plain per-tank rows rather than models, so the same
 * code serves lifetime totals and period deltas — a "recent WN8" is just this
 * calculation over the difference between two snapshots.
 */
class Wn8Calculator
{
    /**
     * Per-metric floors from the WN8 specification. Performance below these is
     * treated as zero contribution rather than negative.
     */
    private const FLOOR_DAMAGE = 0.22;

    private const FLOOR_FRAG = 0.12;

    private const FLOOR_SPOT = 0.38;

    private const FLOOR_DEF = 0.10;

    private const FLOOR_WIN = 0.71;

    /** @var Collection<int, WotExpectedValue>|null */
    private ?Collection $expected = null;

    /**
     * @param  iterable<array{tank_id: int, battles: int, damage_dealt: int, spotted: int, frags: int, dropped_capture_points: int, wins: int}>  $rows
     *                                                                                                                                                  Deliberately does not return a plain `battles` key. Callers spread this
     *                                                                                                                                                  result into their own arrays, and a generic name silently overwrote the
     *                                                                                                                                                  caller's own battle count. `rated_battles + unrated_battles` is the total.
     * @return array{wn8: float|null, rated_battles: int, unrated_battles: int}
     */
    public function forVehicleRows(iterable $rows): array
    {
        $expected = $this->expected();

        $expDamage = $expSpot = $expFrag = $expDef = $expWin = 0.0;
        $damage = $spot = $frag = $def = $wins = 0;
        $rated = 0;
        $unrated = 0;

        foreach ($rows as $row) {
            $battles = (int) ($row['battles'] ?? 0);

            if ($battles <= 0) {
                continue;
            }

            $values = $expected->get($row['tank_id'] ?? null);

            // XVM doesn't publish expected values for every vehicle — new
            // releases, and some event tanks, are missing. Those battles are
            // excluded from the rating rather than scored against a guess, and
            // reported so the UI can be honest about the coverage.
            if (! $values) {
                $unrated += $battles;

                continue;
            }

            $expDamage += $values->exp_damage * $battles;
            $expSpot += $values->exp_spot * $battles;
            $expFrag += $values->exp_frag * $battles;
            $expDef += $values->exp_def * $battles;
            $expWin += $values->exp_win_rate * $battles;

            $damage += (int) ($row['damage_dealt'] ?? 0);
            $spot += (int) ($row['spotted'] ?? 0);
            $frag += (int) ($row['frags'] ?? 0);
            $def += (int) ($row['dropped_capture_points'] ?? 0);
            $wins += (int) ($row['wins'] ?? 0);
            $rated += $battles;
        }

        if ($rated === 0 || $expDamage <= 0) {
            return ['wn8' => null, 'rated_battles' => 0, 'unrated_battles' => $unrated];
        }

        // Win rate is compared as a percentage, matching how XVM publishes it.
        $ratios = [
            'damage' => $damage / $expDamage,
            'spot' => $expSpot > 0 ? $spot / $expSpot : 0.0,
            'frag' => $expFrag > 0 ? $frag / $expFrag : 0.0,
            'def' => $expDef > 0 ? $def / $expDef : 0.0,
            'win' => $expWin > 0 ? ($wins * 100) / $expWin : 0.0,
        ];

        return [
            'wn8' => round($this->score($ratios), 0),
            'rated_battles' => $rated,
            'unrated_battles' => $unrated,
        ];
    }

    /**
     * WN8 for a single vehicle, used for the per-tank column.
     *
     * @param  array<string, mixed>  $row
     */
    public function forVehicle(array $row): ?float
    {
        return $this->forVehicleRows([$row])['wn8'];
    }

    /**
     * @param  array<string, float>  $ratios
     */
    private function score(array $ratios): float
    {
        $damage = max(0.0, ($ratios['damage'] - self::FLOOR_DAMAGE) / (1 - self::FLOOR_DAMAGE));

        // Frags, spotting and defence are each capped relative to damage, so a
        // player cannot inflate the rating by farming one secondary metric in a
        // vehicle they do no damage in.
        $frag = max(0.0, min($damage + 0.2, ($ratios['frag'] - self::FLOOR_FRAG) / (1 - self::FLOOR_FRAG)));
        $spot = max(0.0, min($damage + 0.1, ($ratios['spot'] - self::FLOOR_SPOT) / (1 - self::FLOOR_SPOT)));
        $def = max(0.0, min($damage + 0.1, ($ratios['def'] - self::FLOOR_DEF) / (1 - self::FLOOR_DEF)));
        $win = max(0.0, ($ratios['win'] - self::FLOOR_WIN) / (1 - self::FLOOR_WIN));

        return 980 * $damage
            + 210 * $damage * $frag
            + 155 * $frag * $spot
            + 75 * $def * $frag
            // Capped: a very high win rate over few battles shouldn't dominate.
            + 145 * min(1.8, $win);
    }

    /**
     * Loaded once and held for the life of the instance — a dashboard render
     * scores hundreds of vehicles and would otherwise re-query per tank.
     *
     * @return Collection<int, WotExpectedValue>
     */
    private function expected(): Collection
    {
        return $this->expected ??= WotExpectedValue::all()->keyBy('tank_id');
    }

    /**
     * The conventional colour bands, used to tint the rating in the UI.
     */
    public static function band(?float $wn8): string
    {
        return match (true) {
            $wn8 === null => 'unknown',
            $wn8 < 300 => 'very-bad',
            $wn8 < 600 => 'bad',
            $wn8 < 900 => 'below-average',
            $wn8 < 1250 => 'average',
            $wn8 < 1600 => 'good',
            $wn8 < 1900 => 'very-good',
            $wn8 < 2350 => 'great',
            $wn8 < 2900 => 'unicum',
            default => 'super-unicum',
        };
    }
}
