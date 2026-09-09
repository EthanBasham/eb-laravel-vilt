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
        $tankIds = $targets->flatMap->steps->pluck('tank_id');

        $vehicles = WotVehicle::whereIn('tank_id', $tankIds)->get()->keyBy('tank_id');

        // Vehicles above each line's target — the tier XI the spreadsheet never
        // had a column for. Resolved here rather than by extending the grind
        // path, so no XP total shifts underneath the other views.
        $successors = $this->successors($targets, $vehicles);
        $vehicles = $vehicles->union($successors);

        // The lowest tier any line starts at. Lines that start higher get their
        // missing lower tiers filled in down to here — no further, since a tier
        // no line reaches would only produce a column of zeroes that the rule
        // below immediately drops.
        $floor = (int) ($targets->flatMap->steps->min('tier') ?? 0);

        $ancestors = $this->ancestors($targets, $floor);
        $vehicles = $vehicles->union($ancestors->flatten()->keyBy('tank_id'));

        $purchases = $account->tankPurchases()->get()->keyBy('tank_id');

        // A vehicle the account has battles in is one it owns, or owned. That
        // is the same signal that truncates a research path, so the two agree
        // by construction.
        $played = $account->vehicleSnapshots()->distinct()->pluck('tank_id')->flip();

        $rows = $targets
            ->map(fn (WotGrindTarget $t): array => $this->row($t, $vehicles, $successors, $ancestors, $purchases, $played))
            // A line you have finished buying is not a shopping list.
            ->reject(fn (array $row): bool => $row['is_bought_out'])
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
     * @param  Collection<int, WotVehicle>  $vehicles
     * @param  Collection<int, WotVehicle>  $successors
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  Collection<int, int>  $played
     * @return array<string, mixed>
     */
    private function row(
        WotGrindTarget $target,
        Collection $vehicles,
        Collection $successors,
        Collection $ancestors,
        Collection $purchases,
        Collection $played,
    ): array {
        $steps = $target->steps->sortBy('position')->values();

        // Everything under the vehicle being played was researched through to
        // get there, so it is owned whether or not it is still in the garage.
        $cells = collect($ancestors->get($target->id, []))
            ->map(fn (WotVehicle $v): ?array => $this->cell(
                $v, $v->tank_id, $v->tier, $purchases->get($v->tank_id), true,
            ))
            ->filter()
            ->values();

        $cells = $cells->concat($steps->map(fn (WotGrindStep $s, int $i): array => $this->cell(
            $vehicles->get($s->tank_id),
            $s->tank_id,
            $s->tier,
            $purchases->get($s->tank_id),
            // Position zero is the vehicle the line is being ground in; a path
            // is truncated to start where the player already is.
            $played->has($s->tank_id) || $i === 0,
        ))->filter())->values();

        if ($next = $successors->get($target->tank_id)) {
            $cells->push($this->cell(
                $next,
                $next->tank_id,
                $next->tier,
                $purchases->get($next->tank_id),
                $played->has($next->tank_id),
            ));
        }

        $last = $cells->last();

        return [
            'id' => $target->id,
            'tank_id' => $target->tank_id,
            'name' => $target->vehicle?->name ?? "Tank {$target->tank_id}",
            'nation' => $target->vehicle?->nation,
            'tier' => $target->vehicle?->tier,
            'cells' => $cells->keyBy('tier')->all(),
            // Owned vehicles are not an outlay. Position zero is one of them by
            // default, but by its purchase flag rather than by its position —
            // un-ticking it has to put its price back on the bill.
            'credits_remaining' => (int) $cells
                ->reject(fn (array $c): bool => $c['is_purchased'])
                ->sum('price'),
            'is_bought_out' => (bool) ($last['is_purchased'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cell(
        ?WotVehicle $vehicle,
        int $tankId,
        int $tier,
        ?WotTankPurchase $purchase,
        bool $ownedByDefault,
    ): ?array {
        if (! $vehicle) {
            return null;
        }

        $purchased = $purchase?->is_purchased ?? $ownedByDefault;

        return [
            'tank_id' => $tankId,
            'name' => $vehicle->name,
            'tier' => $tier,
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

    /**
     * The vehicle each line unlocks beyond its target, where one exists.
     *
     * @param  Collection<int, WotGrindTarget>  $targets
     * @param  Collection<int, WotVehicle>  $vehicles
     * @return Collection<int, WotVehicle> keyed by the target's tank_id
     */
    private function successors(Collection $targets, Collection $vehicles): Collection
    {
        $wanted = $targets->mapWithKeys(function (WotGrindTarget $t): array {
            $next = array_keys((array) ($t->vehicle?->next_tanks ?? []));

            return $next === [] ? [] : [$t->tank_id => $next];
        });

        if ($wanted->isEmpty()) {
            return collect();
        }

        $above = WotVehicle::whereIn('tank_id', $wanted->flatten()->unique())
            ->where('tier', '<=', self::TOP_TIER)
            ->get()
            ->keyBy('tank_id');

        return $wanted->map(function (array $ids, int $targetId) use ($above, $vehicles): ?WotVehicle {
            $tier = $vehicles->get($targetId)?->tier ?? 0;

            // Cheapest first: a tier X can branch, and the plan should show the
            // one that is actually next rather than an arbitrary sibling.
            return collect($ids)->map(fn (int $id): ?WotVehicle => $above->get($id))
                ->filter(fn (?WotVehicle $v): bool => $v !== null && $v->tier > $tier)
                ->sortBy('price_credit')
                ->first();
        })->filter();
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

    /**
     * The vehicles below each line's starting tier, keyed by target id.
     *
     * @param  Collection<int, WotGrindTarget>  $targets
     * @return Collection<int, list<WotVehicle>>
     */
    private function ancestors(Collection $targets, int $floor): Collection
    {
        return $targets->mapWithKeys(function (WotGrindTarget $t) use ($floor): array {
            $first = $t->steps->sortBy('position')->first();

            if (! $first || $first->tier <= $floor) {
                return [$t->id => []];
            }

            return [$t->id => $this->tree->ancestorsOf($first->tank_id, $floor)];
        });
    }
}
