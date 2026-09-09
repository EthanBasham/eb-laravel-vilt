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

        // Tracked lines are shown in full however low they start, so the tier
        // the columns reach down to is whichever of the two is lower.
        $floor = min($minTier, (int) ($targets->flatMap->steps->min('tier') ?? $minTier));

        $rows = $targets
            ->map(fn (WotGrindTarget $t): array => $this->targetRow($t, $floor, $purchases, $played))
            ->concat($this->candidateRows($targets, $floor, $minTier, $purchases, $played))
            // A line you have finished buying is not a shopping list.
            ->reject(fn (array $row): bool => $row['is_bought_out'])
            // Same tech-tree nation order as every other vehicle list here.
            ->sortBy(fn (array $r): array => [WotVehicle::rankOf($r['nation']), -$r['tier'], $r['name']])
            ->values();

        return [
            'rows' => $rows->all(),
            'tiers' => $this->tiers($rows),
            /*
             * Summed over the visible rows, so the tab's own total, its footer
             * and the headline card are always the same number.
             *
             * Buying a line's last vehicle therefore settles the line, even if
             * an intermediate tier was never ticked off. That is the intended
             * rule — you cannot research past a vehicle without owning it, so a
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
        int $floor,
        Collection $purchases,
        Collection $played,
    ): array {
        $steps = $target->steps->sortBy('position')->values();
        $first = $steps->first();

        // Everything under the vehicle being played was researched through to
        // get there, so it is owned whether or not it is still in the garage.
        $below = $first ? $this->tree->ancestorsOf($first->tank_id, $floor) : [];

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
     * @param  Collection<int, WotGrindTarget>  $targets
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return Collection<int, array<string, mixed>>
     */
    private function candidateRows(
        Collection $targets,
        int $floor,
        int $minTier,
        Collection $purchases,
        Collection $played,
    ): Collection {
        $tracked = $targets->pluck('tank_id')->flip();

        return $this->tree->vehicles()
            ->reject(fn (WotVehicle $v): bool => $v->is_premium
                || $v->tier < $minTier
                || $v->tier > self::TOP_TIER
                || $played->has($v->tank_id)
                || $tracked->has($v->tank_id))
            // Researchable now: whatever unlocks it is already in the garage.
            ->filter(function (WotVehicle $v) use ($played): bool {
                $predecessor = $this->tree->predecessorOf($v->tank_id);

                return $predecessor !== null && $played->has($predecessor->tank_id);
            })
            ->map(function (WotVehicle $v) use ($floor, $purchases, $played): array {
                $cells = collect($this->tree->ancestorsOf($v->tank_id, $floor))
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

        return [
            'key' => $key,
            'tank_id' => $topTankId,
            'name' => $top?->name ?? "Tank {$topTankId}",
            'nation' => $top?->nation,
            'tier' => $top?->tier,
            'cells' => $cells->keyBy('tier')->all(),
            // Owned vehicles are not an outlay. The line's lowest tier is one of
            // them by default, but by its purchase flag rather than by its
            // position — un-ticking it has to put its price back on the bill.
            'credits_remaining' => (int) $cells
                ->reject(fn (array $c): bool => $c['is_purchased'])
                ->sum('price'),
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
     * The tier columns to render.
     *
     * A tier every visible line has already bought is dropped: the column would
     * be a wall of zeroes saying nothing about what is left to spend. Cells at
     * a dropped tier stay in the payload and simply go unrendered.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    private function tiers(Collection $rows): array
    {
        return $rows->flatMap(fn (array $r): array => array_values($r['cells']))
            ->groupBy('tier')
            ->reject(fn (Collection $cells): bool => $cells->every('is_purchased'))
            ->keys()
            ->map(fn ($tier): int => (int) $tier)
            ->sort()->values()->all();
    }
}
