<?php

namespace App\Services\Wargaming;

/**
 * What blueprint fragments cost, and what they buy.
 *
 * The game's fragment economics, read off `config('wargaming.blueprint_costs')`
 * — hand-transcribed, because the encyclopedia publishes none of it. Two rules
 * here are easy to get wrong from the table alone:
 *
 * Every fragment costs national blueprints *and* universal ones, together. The
 * only choice is which nation pays the national half: the vehicle's own, or a
 * peer in its group at six to one. There is no fragment bought with universal
 * blueprints alone and none bought without them, so the table's three columns
 * are two halves and a surcharge rather than three prices — `universal` is
 * charged on every fragment whichever nation pays, and `group` is what the
 * national half costs when a peer pays it.
 *
 * The last fragment does not remove `percent`. Fragments one to F-1 each take
 * their listed share; the Fth covers whatever is left and lands the vehicle on
 * nothing to research. Tier X is eleven at 7% and then 23%.
 *
 * Kept out of BlueprintBoard because three layers need it: the board costs a
 * plan, XpBoard derives the figure it prints beside the hand-typed one, and
 * UpdateTankPurchaseRequest takes its ceiling from the tier's fragment count.
 */
class BlueprintCost
{
    /**
     * The largest fragment count in the table.
     *
     * The ceiling for a vehicle whose tier is not known — an unknown tank, or
     * one outside II-X — so validation refuses an impossible figure without
     * having to guess which tier it was for.
     */
    public const MAX_FRAGMENTS = 12;

    /**
     * @var array<int, array{national: int, group: int, universal: int, fragments: int, percent: int}>|null
     */
    private ?array $table = null;

    /**
     * Whether blueprints reach this tier at all.
     *
     * False at tier I, which is researched from nothing, and at tier XI, which
     * sits above where the system stops.
     */
    public function supports(int $tier): bool
    {
        return isset($this->table()[$tier]);
    }

    /**
     * How many fragments complete this tier's blueprint.
     */
    public function fragmentsNeeded(int $tier): int
    {
        return $this->table()[$tier]['fragments'] ?? 0;
    }

    /**
     * The share of base research XP one fragment removes, bar the last.
     */
    public function percentPerFragment(int $tier): int
    {
        return $this->table()[$tier]['percent'] ?? 0;
    }

    /**
     * Raw blueprints per fragment, one figure per column.
     *
     * The columns are not three prices — see the class docblock. What one
     * fragment actually costs a given nation is fragmentCost().
     *
     * @return array{national: int, group: int, universal: int}
     */
    public function costPerFragment(int $tier): array
    {
        $row = $this->table()[$tier] ?? ['national' => 0, 'group' => 0, 'universal' => 0];

        return [
            'national' => $row['national'],
            'group' => $row['group'],
            'universal' => $row['universal'],
        ];
    }

    /**
     * What one fragment costs when a given nation pays the national half.
     *
     * The universal half does not move: a peer nation is charged six to one on
     * its own blueprints, and buys no relief from the universal ones every
     * fragment takes.
     *
     * @return array{national: int, universal: int}
     */
    public function fragmentCost(int $tier, bool $isOwnNation): array
    {
        $cost = $this->costPerFragment($tier);

        return [
            'national' => $isOwnNation ? $cost['national'] : $cost['group'],
            'universal' => $cost['universal'],
        ];
    }

    /**
     * The nations a vehicle's fragments can be paid for out of, own first.
     *
     * Its own nation and the peers in its group, which is the whole of the
     * choice — a blueprint never leaves its group. Own first because it is the
     * cheap one and the one most plans are made of; the peers keep the config's
     * order after it.
     *
     * A nation outside every group is its own only source, which is the honest
     * answer rather than a special case: nothing else can pay for it.
     *
     * @return list<string>
     */
    public function payingNations(string $nation): array
    {
        $peers = collect($this->groupOf($nation)['nations'] ?? [])
            ->reject(fn (string $peer): bool => $peer === $nation);

        return [$nation, ...$peers->values()->all()];
    }

    /**
     * XP the fragments built have already taken off the price.
     *
     * A complete blueprint saves the whole cost: the last fragment covers the
     * remainder rather than its listed share, so the figure is not the even
     * share multiplied out. Rounded once on the cumulative share rather than
     * per fragment, so this and xpRemaining() always agree.
     */
    public function xpSaved(int $tier, int $baseXp, int $fragments): int
    {
        if (! $this->supports($tier) || $baseXp <= 0 || $fragments <= 0) {
            return 0;
        }

        if ($fragments >= $this->fragmentsNeeded($tier)) {
            return $baseXp;
        }

        return (int) round($baseXp * $this->percentPerFragment($tier) * $fragments / 100);
    }

    /**
     * What the vehicle still costs to research with those fragments built.
     */
    public function xpRemaining(int $tier, int $baseXp, int $fragments): int
    {
        return max(0, $baseXp - $this->xpSaved($tier, $baseXp, $fragments));
    }

    /**
     * What a plan comes to: one line per nation that could pay, and the totals.
     *
     * Every nation in the group gets a line whether or not anything is planned
     * against it, so the planner draws a fixed set of rows and the client never
     * has to work out which nations a vehicle may draw on — that rule has one
     * home, and it is payingNations() above.
     *
     * Nothing is capped. The raw spend of a plan is its raw spend even where it
     * overshoots the blueprint; xpRemaining() is the thing that clamps.
     *
     * @param  array<string, int>  $fragmentsByNation
     * @return array{lines: list<array{nation: string, is_own: bool, fragments: int, national: int, universal: int, per_fragment: array{national: int, universal: int}}>, fragments: int, national_blueprints: int, universal_blueprints: int}
     */
    public function plan(int $tier, string $nation, array $fragmentsByNation): array
    {
        $lines = collect($this->payingNations($nation))->map(function (string $paying) use ($tier, $nation, $fragmentsByNation): array {
            $fragments = max(0, (int) ($fragmentsByNation[$paying] ?? 0));
            $cost = $this->fragmentCost($tier, $paying === $nation);

            return [
                'nation' => $paying,
                'is_own' => $paying === $nation,
                'fragments' => $fragments,
                'national' => $fragments * $cost['national'],
                'universal' => $fragments * $cost['universal'],
                'per_fragment' => $cost,
            ];
        });

        return [
            'lines' => $lines->all(),
            'fragments' => (int) $lines->sum('fragments'),
            /*
             * Totalled across nations, which is what the board-wide figure has
             * always been: how many blueprints the plans would spend, not whose
             * stack they come out of. A shortfall against one nation's stock is
             * answerable now that a line names its nation, but nothing here
             * claims it — see the Blueprints rules.
             */
            'national_blueprints' => (int) $lines->sum('national'),
            'universal_blueprints' => (int) $lines->sum('universal'),
        ];
    }

    /**
     * The group a nation's blueprints can be spent across, null outside one.
     *
     * @return array{key: string, name: string, nations: list<string>}|null
     */
    public function groupOf(string $nation): ?array
    {
        foreach ((array) config('wargaming.nation_groups') as $key => $group) {
            if (in_array($nation, $group['nations'], strict: true)) {
                return ['key' => $key, 'name' => $group['name'], 'nations' => array_values($group['nations'])];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{national: int, group: int, universal: int, fragments: int, percent: int}>
     */
    private function table(): array
    {
        return $this->table ??= (array) config('wargaming.blueprint_costs');
    }
}
