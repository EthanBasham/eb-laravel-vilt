<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;

/**
 * The Tanks to Purchase view: credits, and nothing else.
 *
 * One row per research line, one column per tier, one cell per vehicle. The
 * other views answer "how much XP is left"; this one only answers "what do I
 * still have to pay, and for what" — so no XP, modules or banked figures reach
 * it, and a line whose last vehicle is bought drops out entirely.
 *
 * Rows are not limited to tracked grind targets. Anything one research step
 * from a vehicle the account has played is something it could buy, whether or
 * not a plan was ever written down for it.
 */
class PurchaseBoard
{
    /** Tier XI sits above the tech tree's old ceiling; paths stop at X. */
    private const TOP_TIER = 11;

    /**
     * The tier a row is named after.
     *
     * A branch is known by its tier X in game and in every community tool, so
     * that is the name the row carries whatever tier it was entered at.
     */
    private const NAMED_TIER = 10;

    /**
     * The tier a row's cells reach down to.
     *
     * Distinct from config('wargaming.purchase_min_tier'), which decides what
     * earns a row of its own. Nothing below that gets a row; every row shows
     * its whole line regardless, so a tier III you do not actually own has
     * somewhere to appear and something to un-tick.
     *
     * This supersedes the earlier "two floors" arrangement, where the column
     * range was min(that config, the lowest tier any tracked line started at).
     * That computation existed to answer "how low does any line start?", which
     * stops being a question once the answer is always the bottom of the tree.
     */
    private const FLOOR_TIER = 1;

    public function __construct(private readonly TechTree $tree) {}

    /**
     * The whole tech tree as one row per line.
     *
     * @return array<string, mixed>
     */
    public function for(WotAccount $account): array
    {
        $purchases = $account->tankPurchases()->get()->keyBy('tank_id');

        // A vehicle the account has battles in is one it owns, or owned. That
        // is the same signal that truncates a research path, so the two agree
        // by construction.
        $played = $account->vehicleSnapshots()->distinct()->pluck('tank_id')->flip();

        // Below this a line is pocket change rather than something a budget is
        // planned around, and the tree's low tiers are littered with dead ends
        // that unlock nothing and are not lines in any useful sense.
        $minTier = (int) config('wargaming.purchase_min_tier');

        $rows = $this->claimShared(
            $this->lineRows($minTier, $purchases, $played)
                // Same tech-tree nation order as every other vehicle list here.
                // Sorted before claiming, so the row that owns a shared vehicle
                // is the one you meet first reading down the board.
                ->sortBy(fn (array $r): array => [WotVehicle::rankOf($r['nation']), -$r['tier'], $r['name']])
                ->values()
        );

        return [
            'rows' => $rows->all(),
            ...$this->tierColumns($rows),
            /*
             * Summed over the visible rows, so the tab's own total, its footer
             * and the headline card are always the same number. Each vehicle is
             * in exactly one row's total after claimShared(), so a tank on two
             * lines is charged once.
             *
             * Buying a line's last vehicle settles the line, even if an
             * intermediate tier was never ticked off. That is the intended rule
             * — you cannot research past a vehicle without owning it, so a
             * bought tier X means the tiers below it were bought too.
             */
            'credits_required' => (int) $rows->sum('credits_remaining'),
        ];
    }

    /**
     * One row per line in the tech tree.
     *
     * A line is a branch top — a vehicle nothing else researches from — with
     * its whole lineage behind it. That is the board: the tree itself, not a
     * projection of what you happen to be grinding. Which of it you look at is
     * the filters' job.
     *
     * The tier floor keeps out the low-tier dead ends. 159 vehicles unlock
     * nothing, but 86 of them are tier II–VII oddities that are not lines in
     * any useful sense; the rest are the tier X and XI the tree actually ends
     * at.
     *
     * Ownership is read per vehicle rather than assumed by position: a cell is
     * owned if it has been played. Everything below something owned is filled
     * in by the rule in row(), which is what makes a line you finished read as
     * finished and a line you have never touched cost full price.
     *
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(int $minTier, Collection $purchases, Collection $played): Collection
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
                // which is a different question from what to grind towards, and
                // as rows they were a single cell with no path behind them.
                //
                // Checked on the predecessor rather than on a flag because the
                // encyclopedia does not publish one: is_premium is false for
                // all of them, and is_gift was dropped as unused.
                || $this->tree->predecessorOf($v->tank_id) === null)
            ->map(function (WotVehicle $v) use ($purchases, $played): array {
                $cells = collect($this->tree->ancestorsOf($v->tank_id, self::FLOOR_TIER))
                    ->push($v)
                    ->map(fn (WotVehicle $c): ?array => $this->cell(
                        $c,
                        $purchases->get($c->tank_id),
                        $played->has($c->tank_id),
                    ));

                return $this->row("l{$v->tank_id}", $v->tank_id, $cells, $purchases, $played);
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>|null>  $cells
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return array<string, mixed>
     */
    private function row(
        string $key,
        int $topTankId,
        Collection $cells,
        Collection $purchases,
        Collection $played,
    ): array {
        $top = $this->tree->vehicles()->get($topTankId);

        $cells = $cells->filter()->values();

        /*
         * Anything below a vehicle you have *played* was researched through to
         * reach it, so it was owned too.
         *
         * It needs saying because "played" means in the garage with battles:
         * sell a tank after moving up the line and every trace of having owned
         * it goes with it, which left a researched-past tier IX reading as
         * still to buy under a tier X you have battles in.
         *
         * The trigger is deliberately play history and nothing else. Inferring
         * from is_purchased instead would make the rule contagious — ticking a
         * tier IX as bought would silently mark the VIII beneath it bought and
         * researched as well, which is a statement about the VIII that you did
         * not make. Tanks get sold, and saying so has to stay possible after
         * the initial state is worked out.
         *
         * An explicit purchase record wins over the inference either way.
         */
        $owned = false;
        $cells = $cells
            ->reverse()
            ->map(function (array $cell) use (&$owned, $purchases, $played): array {
                if ($owned && ! $purchases->has($cell['tank_id'])) {
                    $cell['is_purchased'] = true;
                    $cell['is_unlocked'] = true;
                }

                $owned = $owned || $played->has($cell['tank_id']);

                return $cell;
            })
            ->reverse()
            ->values();

        /*
         * Rows reach this board from two directions — a tracked target, whose
         * top may be a IX or an XI, and a researchable candidate, which can be
         * any tier — so naming each after its own entry point put the same
         * branch on screen under different names. The tier X is the constant.
         *
         * Falls back to the row's top for a line that never reaches X, which is
         * the best name available rather than a deliberate second choice.
         */
        $named = $cells->firstWhere('tier', self::NAMED_TIER);
        $namedBy = $named ? $this->tree->vehicles()->get($named['tank_id']) : $top;

        return [
            'key' => $key,
            'tank_id' => $topTankId,
            /*
             * short_name, not name: the encyclopedia ships both, and the short
             * form is the one written down — "Obj. 279 (e)" against "Object 279
             * early". Of the 122 tier X vehicles synced, 55 differ from their
             * long form and none are null; the column is nullable all the same,
             * so name stays as a fallback.
             */
            'name' => $namedBy?->short_name ?? $namedBy?->name ?? "Tank {$topTankId}",
            'nation' => $top?->nation,
            'tier' => $top?->tier,
            'type' => $namedBy?->type,
            'cells' => $cells->keyBy('tier')->all(),
            // credits_remaining is not set here: it depends on which cells this
            // row actually pays for, which is not known until rows are sorted
            // and shared vehicles claimed. claimShared() adds it.
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cell(?WotVehicle $vehicle, ?WotTankPurchase $purchase, bool $ownedByDefault): ?array
    {
        if (! $vehicle) {
            return null;
        }

        $purchased = $purchase?->is_purchased ?? $ownedByDefault;

        return [
            'tank_id' => $vehicle->tank_id,
            'name' => $vehicle->name,
            'tier' => $vehicle->tier,
            // The override is what you will actually pay; the API price is kept
            // alongside so the field can be reset and so a discount is visible
            // as a discount rather than as a mysterious number.
            'price' => (int) ($purchase?->price_credit ?? $vehicle->price_credit ?? 0),
            'api_price' => $vehicle->price_credit === null ? null : (int) $vehicle->price_credit,
            'is_discounted' => $purchase?->price_credit !== null
                && (int) $purchase->price_credit !== (int) $vehicle->price_credit,
            'is_purchased' => $purchased,
            // Buying implies researching, whatever the stored flag says.
            'is_unlocked' => $purchased || ($purchase?->is_unlocked ?? false),
            /*
             * Filled in by claimShared() once the rows are named and sorted.
             * Seeded here so every cell has the same shape whether it ends up
             * shared or not — the client reads these on every cell, and a
             * missing key would read as undefined rather than as false.
             */
            'is_shared' => false,
            'shared_with' => null,
        ];
    }

    /** The vehicle a line unlocks beyond its top, where one exists. */
    private function successorOf(?WotVehicle $top): ?WotVehicle
    {
        if (! $top) {
            return null;
        }

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

    /**
     * The tier columns, and which of them are already settled.
     *
     * Every tier holding a cell gets a column. The board is the whole tree, and
     * which part of it you look at belongs to the filter row rather than to the
     * server — this used to drop a fully-bought tier outright, which meant a
     * column you could not click was also a column you could not un-tick
     * anything in.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{tiers: list<int>, bought_tiers: list<int>}
     */
    private function tierColumns(Collection $rows): array
    {
        $byTier = $rows
            ->flatMap(fn (array $r): array => array_values($r['cells']))
            ->groupBy('tier')
            ->sortKeys();

        return [
            'tiers' => $byTier->keys()->map(fn ($tier): int => (int) $tier)->all(),
            /*
             * A tier is settled when nothing on it is still owed. Shared cells
             * count as settled: another row is paying for that vehicle, so a
             * column held open only by duplicates would be a column of zeroes,
             * which is what this rule exists to keep off the screen.
             *
             * shownRows on the client hides a row with nothing left to pay in
             * the visible tiers, so this predicate has to stay the exact
             * inverse of cellCost — a tier holding anything a row still owes
             * must never land here, or hiding it by default would take a row
             * off the board with it.
             */
            'bought_tiers' => $byTier
                ->filter(fn (Collection $cells): bool => $cells->every(
                    fn (array $cell): bool => $cell['is_purchased'] || $cell['is_shared'],
                ))
                ->keys()->map(fn ($tier): int => (int) $tier)->all(),
        ];
    }

    /**
     * Assign each vehicle to one row, and total what that row owes.
     *
     * A vehicle can sit on more than one line — twelve Soviet lines share the
     * MS-1 — and it is still one tank bought once. The first row to show it in
     * display order keeps it as an editable cell and pays for it; the rest
     * carry it as a read-only figure naming where it lives and contribute
     * nothing to their own total, so the row totals still sum to the grand
     * total.
     *
     * Runs after the sort for exactly that reason: "first" has to mean first
     * on screen, or the one editable copy lands on an arbitrary row.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function claimShared(Collection $rows): Collection
    {
        $owner = [];

        /*
         * Two passes, because a vehicle has to be owned by a row that actually
         * pays for it.
         *
         * A fully-owned line shows the same low tiers as a line still working
         * up to them, and it sorts wherever its name puts it. If it claimed one
         * of those cells by being first, it would contribute nothing (it owns
         * the tank) while the row that still owes would carry it read-only and
         * contribute nothing either — and the price would drop off the board
         * silently. So rows that still owe a vehicle get first refusal on it.
         */
        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                if (! $cell['is_purchased'] && ! isset($owner[$cell['tank_id']])) {
                    $owner[$cell['tank_id']] = ['key' => $row['key'], 'name' => $row['name']];
                }
            }
        }

        // Whatever nobody owes — every copy already bought — falls to the first
        // row that shows it, so the duplicates still read as duplicates.
        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                if (! isset($owner[$cell['tank_id']])) {
                    $owner[$cell['tank_id']] = ['key' => $row['key'], 'name' => $row['name']];
                }
            }
        }

        return $rows->map(function (array $row) use ($owner): array {
            foreach ($row['cells'] as $tier => $cell) {
                if ($owner[$cell['tank_id']]['key'] !== $row['key']) {
                    $row['cells'][$tier]['is_shared'] = true;
                    $row['cells'][$tier]['shared_with'] = $owner[$cell['tank_id']]['name'];
                }
            }

            // Owned vehicles are not an outlay, and neither is one another row
            // is already paying for. The line's lowest tier is owned by default,
            // but by its purchase flag rather than by its position — un-ticking
            // it has to put its price back on the bill.
            $row['credits_remaining'] = (int) collect($row['cells'])
                ->reject(fn (array $c): bool => $c['is_purchased'] || $c['is_shared'])
                ->sum('price');

            return $row;
        });
    }
}
