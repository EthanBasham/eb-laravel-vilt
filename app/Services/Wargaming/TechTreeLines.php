<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotVehicle;

/**
 * The tech tree as one row per research line.
 *
 * A line is a branch top — a vehicle nothing else researches from — with its
 * whole lineage behind it. Both boards that lay the tree out as a grid are
 * built on this: Tanks to Purchase decorates each vehicle with what it costs,
 * Free XP with the modules on it. Neither has an opinion about which lines
 * exist, which is why that question lives here rather than in either of them.
 */
class TechTreeLines
{
    /** Tier XI sits above the tech tree's old ceiling; paths stop at X. */
    private const TOP_TIER = 11;

    /**
     * The tier a row is named after.
     *
     * A branch is known by its tier X in game and in every community tool, so
     * that is the name the row carries whatever tier it was entered at.
     */
    public const NAMED_TIER = 10;

    /**
     * The tier a row's cells reach down to.
     *
     * Distinct from the minimum tier a branch top needs to earn a row at all:
     * nothing below that gets a row, but every row shows its whole line, so a
     * tier III has somewhere to appear.
     */
    public const FLOOR_TIER = 1;

    public function __construct(private readonly TechTree $tree) {}

    /**
     * Every line in the tree, in display order.
     *
     * The tier floor keeps out the low-tier dead ends. 159 vehicles unlock
     * nothing, but 86 of them are tier II–VII oddities that are not lines in
     * any useful sense; the rest are the tier X and XI the tree actually ends
     * at.
     *
     * @return Collection<int, array{key: string, tank_id: int, name: string, nation: ?string, tier: ?int, type: ?string, vehicles: Collection<int, WotVehicle>}>
     */
    public function lines(int $minTier): Collection
    {
        return $this->tree->vehicles()
            ->reject(fn (WotVehicle $v): bool => $v->is_premium
                || $v->tier < $minTier
                || $v->tier > self::TOP_TIER
                || $this->successorOf($v) !== null
                // Nothing researches into a collector's vehicle, so it has no
                // line to head — the 113, both AMX 30s, the Jagdpanther II and
                // the T-62A all sit in the tree with neither a predecessor nor
                // a successor. They are bought outright rather than researched,
                // and as rows they were a single cell with no path behind them.
                //
                // Checked on the predecessor rather than on a flag because the
                // encyclopedia does not publish one: is_premium is false for
                // all of them, and is_gift was dropped as unused.
                || $this->tree->predecessorOf($v->tank_id) === null)
            ->map(fn (WotVehicle $v): array => $this->line($v))
            // Same tech-tree nation order as every other vehicle list here.
            ->sortBy(fn (array $l): array => [WotVehicle::rankOf($l['nation']), -$l['tier'], $l['name']])
            ->values();
    }

    /**
     * @return array{key: string, tank_id: int, name: string, nation: ?string, tier: ?int, type: ?string, vehicles: Collection<int, WotVehicle>}
     */
    private function line(WotVehicle $top): array
    {
        $vehicles = collect($this->tree->ancestorsOf($top->tank_id, self::FLOOR_TIER))
            ->push($top)
            ->keyBy(fn (WotVehicle $v): int => $v->tier);

        /*
         * Rows reach a board from two directions — a line entered at its tier X
         * and one running on to a tier XI — so naming each after its own top
         * put the same branch on screen under two names. The tier X is the
         * constant.
         *
         * Falls back to the top for a line that never reaches X, which is the
         * best name available rather than a deliberate second choice.
         */
        $namedBy = $vehicles->get(self::NAMED_TIER) ?? $top;

        return [
            'key' => "l{$top->tank_id}",
            'tank_id' => $top->tank_id,
            /*
             * short_name, not name: the encyclopedia ships both, and the short
             * form is the one written down — "Obj. 279 (e)" against "Object 279
             * early". Of the 122 tier X vehicles synced, 55 differ from their
             * long form and none are null; the column is nullable all the same,
             * so name stays as a fallback.
             */
            'name' => $namedBy->short_name ?? $namedBy->name ?? "Tank {$top->tank_id}",
            'nation' => $top->nation,
            'tier' => $top->tier,
            'type' => $namedBy->type,
            'vehicles' => $vehicles,
        ];
    }

    /** The vehicle a line unlocks beyond its top, where one exists. */
    private function successorOf(WotVehicle $top): ?WotVehicle
    {
        // Cheapest first: a tier X can branch, and the plan should show the one
        // that is actually next rather than an arbitrary sibling.
        return collect(array_keys((array) ($top->next_tanks ?? [])))
            ->map(fn ($id): ?WotVehicle => $this->tree->vehicles()->get((int) $id))
            ->filter(fn (?WotVehicle $v): bool => $v !== null
                && $v->tier > $top->tier
                && $v->tier <= self::TOP_TIER)
            ->sortBy('price_credit')
            ->first();
    }
}
