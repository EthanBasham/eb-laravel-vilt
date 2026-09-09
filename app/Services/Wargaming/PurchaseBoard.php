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
 * Rows are not limited to tracked grind targets. Which lines exist is
 * TechTreeLines' question; this class only decorates them with prices.
 */
class PurchaseBoard
{
    public function __construct(private readonly TechTreeLines $lines) {}

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
        $rows = $this->claimShared($this->lineRows($minTier, $purchases, $played));

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
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(int $minTier, Collection $purchases, Collection $played): Collection
    {
        return $this->lines->lines($minTier)
            ->map(fn (array $line): array => $this->row($line, $purchases, $played));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return array<string, mixed>
     */
    private function row(array $line, Collection $purchases, Collection $played): array
    {
        $cells = $line['vehicles']
            ->map(fn (WotVehicle $v): array => $this->cell(
                $v,
                $purchases->get($v->tank_id),
                $played->has($v->tank_id),
            ))
            // Lowest tier first, which is the order the back-fill below walks.
            ->sortBy('tier')
            ->values();

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

        return [
            ...collect($line)->except('vehicles')->all(),
            'cells' => $cells->keyBy('tier')->all(),
            // credits_remaining is not set here: it depends on which cells this
            // row actually pays for, which is not known until shared vehicles
            // are claimed. claimShared() adds it.
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(WotVehicle $vehicle, ?WotTankPurchase $purchase, bool $ownedByDefault): array
    {
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
