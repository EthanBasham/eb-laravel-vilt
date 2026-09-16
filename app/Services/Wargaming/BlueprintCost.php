<?php

namespace App\Services\Wargaming;

/**
 * What blueprint fragments cost, and what they buy.
 *
 * The game's fragment economics, read off `config('wargaming.blueprint_costs')`
 * — hand-transcribed, because the encyclopedia publishes none of it. Two rules
 * here are easy to get wrong from the table alone:
 *
 * The three cost columns are alternatives chosen per fragment, not a combined
 * price. One fragment comes from own-nation blueprints, or from another nation
 * in the same group at six to one, or from universal ones, and a single
 * blueprint may mix the three across its fragments.
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
     * Raw blueprints per fragment, one figure per source.
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
     * What a plan costs in raw blueprints.
     *
     * Group fragments are charged at the group rate, which is why they are
     * counted apart from own-nation ones and then folded in here: both are
     * national blueprints, and only the rate differs. Nothing is capped — the
     * raw spend of a plan is its raw spend even where it overshoots the
     * blueprint, which xpRemaining() is the thing that clamps.
     *
     * @return array{national_blueprints: int, universal_blueprints: int, fragments: int}
     */
    public function plan(int $tier, int $own, int $group, int $universal): array
    {
        $cost = $this->costPerFragment($tier);

        return [
            'national_blueprints' => $own * $cost['national'] + $group * $cost['group'],
            'universal_blueprints' => $universal * $cost['universal'],
            'fragments' => $own + $group + $universal,
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
