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
 * Which lines exist is TechTreeLines' question; this class only decorates them
 * with prices.
 */
class PurchaseBoard
{
    public function __construct(
        private readonly TechTreeLines $lines,
        private readonly LineOwnership $ownership,
        private readonly AccountProgress $progress,
    ) {}

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
        $minTier = (int) config('wargaming.line_min_tier');

        // Claimed in display order, so the row that owns a shared vehicle is
        // the one you meet first reading down the board. TechTreeLines already
        // sorts, which is why nothing re-sorts here.
        $rows = $this->claimShared($this->lineRows($minTier, $purchases, $played, $this->progress->for($account)));

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
     * One row per line, each vehicle decorated with what it still costs.
     *
     * Ownership is read per vehicle rather than assumed by position: a cell is
     * owned if it has been played. Everything below something owned is filled
     * in by the rule in row(), which is what makes a line you finished read as
     * finished and a line you have never touched cost full price.
     *
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @param  array<int, array{is_purchased: bool, is_unlocked: bool}>  $researched
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(int $minTier, Collection $purchases, Collection $played, array $researched): Collection
    {
        return $this->lines->lines($minTier)
            ->map(fn (array $line): array => $this->row($line, $purchases, $played, $researched));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @param  array<int, array{is_purchased: bool, is_unlocked: bool}>  $researched
     * @return array<string, mixed>
     */
    private function row(array $line, Collection $purchases, Collection $played, array $researched): array
    {
        // Ownership is read per vehicle and then inferred down the line, which
        // is what makes a line you finished read as finished and a line you
        // have never touched cost full price. LineOwnership owns that rule, so
        // this board and XP Remaining bill the same tanks.
        $owned = $this->ownership->along($line['vehicles'], $purchases, $played);

        $byTier = $line['vehicles'];

        $cells = $line['vehicles']
            ->map(fn (WotVehicle $v): array => $this->cell(
                $v,
                $purchases->get($v->tank_id),
                $owned[$v->tank_id],
                $researched[$v->tank_id]['is_unlocked'] ?? $owned[$v->tank_id]['is_unlocked'],
                // Nothing on this line researches into it, so there is no
                // unlock to tick anywhere and none is owed.
                ! $byTier->has($v->tier - 1),
            ))
            ->sortBy('tier')
            ->values();

        return [
            ...collect($line)->except('vehicles')->all(),
            'cells' => $cells->keyBy('tier')->all(),
            // credits_remaining is not set here: it depends on which cells this
            // row actually pays for, which is not known until shared vehicles
            // are claimed. claimShared() adds it.
        ];
    }

    /**
     * The two halves of a cell come from different places on purpose.
     *
     * What you owe is a per-line question — a tank bought on one line is still
     * owed on another that has not reached it, which is what stops a converging
     * pair charging twice — so is_purchased comes from LineOwnership.
     *
     * Whether it is researched is a fact about the tank, and since the tick for
     * it now lives on XP Remaining it has to be read the way that board reads
     * it: unioned across every line by AccountProgress. Taken per line instead,
     * a tank researched under a line you have played showed as unresearched on
     * a line you had not, and there was no tick on either board to fix it with.
     *
     * @param  array{is_purchased: bool, is_unlocked: bool}  $owned
     * @return array<string, mixed>
     */
    private function cell(WotVehicle $vehicle, ?WotTankPurchase $purchase, array $owned, bool $isUnlocked, bool $isRoot): array
    {
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
            'is_purchased' => $owned['is_purchased'],
            'is_unlocked' => $isUnlocked,
            // Read-only here: the lock is ticked on XP Remaining, against the
            // unlock that leads to this tank. A root has no such unlock, which
            // the cell says rather than leaving a lock that cannot be opened.
            'is_root' => $isRoot,
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
