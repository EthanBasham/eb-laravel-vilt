<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
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
     * @param  Collection<int, WotGrindTarget>  $targets
     * @return array<string, mixed>
     */
    public function for(WotAccount $account, Collection $targets): array
    {
        $purchases = $account->tankPurchases()->get()->keyBy('tank_id');

        // A vehicle the account has battles in is one it owns, or owned. That
        // is the same signal that truncates a research path, so the two agree
        // by construction.
        $played = $account->vehicleSnapshots()->distinct()->pluck('tank_id')->flip();

        // Untracked buyables stop here; below it a vehicle is pocket change
        // rather than something a budget is planned around.
        $minTier = (int) config('wargaming.purchase_min_tier');

        $tracked = $targets
            ->map(fn (WotGrindTarget $t): array => $this->targetRow($t, $purchases, $played))
            ->values();

        // Every vehicle a tracked line already shows, at any tier — not just the
        // targets themselves. A tier IX sitting mid-path is on the board once
        // already; giving it a second row of its own is the same tank twice.
        $covered = $tracked
            ->flatMap(fn (array $r): array => array_column($r['cells'], 'tank_id'))
            ->flip();

        $rows = $this->claimShared(
            $tracked
                ->concat($this->candidateRows($covered, $minTier, $purchases, $played))
                // A line you have finished buying is not a shopping list. This
                // goes before anything is claimed, so a row nobody can see
                // never takes a vehicle away from a row they can — the claim
                // would name a row that is not on the board, and nothing would
                // pay for the tank.
                ->reject(fn (array $row): bool => $row['is_bought_out'])
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
     * A tracked line: its steps, plus the tiers it was truncated above.
     *
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return array<string, mixed>
     */
    private function targetRow(
        WotGrindTarget $target,
        Collection $purchases,
        Collection $played,
    ): array {
        $steps = $target->steps->sortBy('position')->values();
        $first = $steps->first();

        // Everything under the vehicle being played was researched through to
        // get there, so it is owned whether or not it is still in the garage.
        $below = $first ? $this->tree->ancestorsOf($first->tank_id, self::FLOOR_TIER) : [];

        $cells = collect($below)
            ->map(fn (WotVehicle $v): ?array => $this->cell($v, $purchases->get($v->tank_id), true))
            ->concat($steps->map(fn (WotGrindStep $s, int $i): ?array => $this->cell(
                $this->tree->vehicles()->get($s->tank_id),
                $purchases->get($s->tank_id),
                // Position zero is the vehicle the line is being ground in; a
                // path is truncated to start where the player already is.
                $played->has($s->tank_id) || $i === 0,
            )));

        return $this->row("t{$target->id}", $target->tank_id, $cells, $purchases, $played);
    }

    /**
     * Vehicles one research from something played, with no tracked line.
     *
     * @param  Collection<int, int>  $covered  tank ids a tracked line already shows
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return Collection<int, array<string, mixed>>
     */
    private function candidateRows(
        Collection $covered,
        int $minTier,
        Collection $purchases,
        Collection $played,
    ): Collection {
        $pool = $this->tree->vehicles()
            ->reject(fn (WotVehicle $v): bool => $v->is_premium
                || $v->tier < $minTier
                || $v->tier > self::TOP_TIER
                || $played->has($v->tank_id)
                || $covered->has($v->tank_id))
            // Researchable now: whatever unlocks it is already in the garage.
            ->filter(function (WotVehicle $v) use ($played): bool {
                $predecessor = $this->tree->predecessorOf($v->tank_id);

                return $predecessor !== null && $played->has($predecessor->tank_id);
            });

        // Two candidates on one branch would each render the other's line. Only
        // the topmost keeps a row; the rest become cells in it. Safe from
        // cycles because a lineage strictly descends in tier.
        $subsumed = $pool
            ->flatMap(fn (WotVehicle $v): array => array_map(
                fn (WotVehicle $a): int => $a->tank_id,
                $this->tree->ancestorsOf($v->tank_id, self::FLOOR_TIER),
            ))
            ->flip();

        return $pool
            ->reject(fn (WotVehicle $v): bool => $subsumed->has($v->tank_id))
            ->map(function (WotVehicle $v) use ($purchases, $played): array {
                $cells = collect($this->tree->ancestorsOf($v->tank_id, self::FLOOR_TIER))
                    ->map(fn (WotVehicle $a): ?array => $this->cell($a, $purchases->get($a->tank_id), true))
                    ->push($this->cell($v, $purchases->get($v->tank_id), false));

                return $this->row("v{$v->tank_id}", $v->tank_id, $cells, $purchases, $played);
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

        // The vehicle above the line's top — the tier XI the spreadsheet never
        // had a column for. Resolved here rather than by extending the research
        // path, so no XP total shifts underneath the other views.
        if ($next = $this->successorOf($top)) {
            $cells = $cells->push($this->cell($next, $purchases->get($next->tank_id), $played->has($next->tank_id)));
        }

        $cells = $cells->filter()->values();

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
            'is_bought_out' => (bool) ($cells->last()['is_purchased'] ?? false),
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

        return $rows->map(function (array $row) use (&$owner): array {
            foreach ($row['cells'] as $tier => $cell) {
                if (isset($owner[$cell['tank_id']])) {
                    $row['cells'][$tier]['is_shared'] = true;
                    $row['cells'][$tier]['shared_with'] = $owner[$cell['tank_id']];

                    continue;
                }

                $owner[$cell['tank_id']] = $row['name'];
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
