<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotBlueprint;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;

/**
 * The Blueprints view: what a vehicle costs, and how far its blueprint has come.
 *
 * A cell carries the fragments built against the fragments the tier takes, the
 * vehicle's undiscounted research cost, and where the remaining fragments are
 * meant to come from. The figures behind it are hand-transcribed — the
 * encyclopedia publishes neither the fragments a vehicle needs nor the discount
 * they buy — and live in config('wargaming.blueprint_costs'); the arithmetic is
 * BlueprintCost's.
 *
 * This reverses what the board was first built on, that the fragments-to-
 * discount curve could not be derived. It can, now that the curve is known. The
 * XP Remaining board still keeps its hand-typed research_xp, because that is
 * what the game actually quoted; the derived figure is printed beside it rather
 * than over it, and the two disagreeing is worth seeing.
 */
class BlueprintBoard
{
    /**
     * The tiers a fragment can be spent on.
     *
     * Blueprints exist for tiers II-X only: a tier I is researched from
     * nothing, and tier XI sits above where the system stops. Neither gets a
     * column here — an empty cell you can type a number into is worse than no
     * cell at all — which is a rule this board enforces alone. The other three
     * still show the whole line, because XP and credits are owed at every tier.
     */
    private const MIN_TIER = 2;

    private const MAX_TIER = 10;

    public function __construct(
        private readonly TechTreeLines $lines,
        private readonly AccountProgress $progress,
        private readonly BlueprintCost $cost,
    ) {}

    /**
     * The whole tech tree as one row per line.
     *
     * @return array<string, mixed>
     */
    public function for(WotAccount $account): array
    {
        $rows = $this->claimShared($this->lineRows($account));

        return [
            'rows' => $rows->all(),
            'tiers' => $this->tierColumns($rows),
            'blueprint_fragments' => (int) $rows->sum('fragments'),
            // What the plans across the whole board come to, in blueprints of
            // every nation together. Recorded and totalled, never measured
            // against the stock below — see plannedTotals().
            'planned' => $this->plannedTotals($rows),
            // Carried on this board's payload rather than as a sibling prop, so
            // the reload every edit on the tab already asks for brings it back.
            'stock' => $this->stock($account),
        ];
    }

    /**
     * Blueprints held, one entry per nation plus the universal stack.
     *
     * Every nation is listed whether or not a count has been typed for it, in
     * tech-tree order with universal last, so the page draws a fixed row and
     * never has to know which stacks happen to exist. This is the raw material
     * the fragments on the cells below are built from: national blueprints
     * spend on their own nation, universal ones on any.
     *
     * @return list<array{nation: string, label: string, quantity: int}>
     */
    private function stock(WotAccount $account): array
    {
        $held = WotBlueprint::where('wot_account_id', $account->id)->pluck('quantity', 'nation');

        return collect((array) config('wargaming.nations'))
            ->map(fn (string $label, string $nation): array => ['nation' => $nation, 'label' => $label])
            ->values()
            ->push(['nation' => WotBlueprint::UNIVERSAL, 'label' => 'Universal'])
            ->map(fn (array $stack): array => [...$stack, 'quantity' => (int) ($held[$stack['nation']] ?? 0)])
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(WotAccount $account): Collection
    {
        $purchases = $account->tankPurchases()->get()->keyBy('tank_id');
        $owned = $this->progress->for($account);

        return $this->lines->lines((int) config('wargaming.line_min_tier'))
            ->map(function (array $line) use ($purchases, $owned): array {
                $byTier = $line['vehicles'];

                $cells = $byTier
                    // Narrowed after $byTier is captured, not before: the cell
                    // still asks the unfiltered line whether a tier II is
                    // researched from a tier I.
                    ->filter(fn (WotVehicle $v, int $tier): bool => $tier >= self::MIN_TIER && $tier <= self::MAX_TIER)
                    ->map(fn (WotVehicle $v, int $tier): array => $this->cell(
                        $v,
                        // The vehicle below it on this line. Where there is
                        // none, this is the root of the tree and there is
                        // nothing to research — so nothing to blueprint.
                        $byTier->get($tier - 1),
                        $purchases->get($v->tank_id),
                        $owned[$v->tank_id] ?? [],
                    ))
                    ->sortBy('tier')
                    ->values();

                return [
                    ...collect($line)->except('vehicles')->all(),
                    'cells' => $cells->keyBy('tier')->all(),
                    // fragments is not set here: claimShared() decides which
                    // cells this row counts.
                ];
            });
    }

    /**
     * One vehicle: what it costs, what has been built, and what is planned.
     *
     * The base XP is read off this line's own predecessor rather than the
     * cheapest one anywhere in the tree, which is what XpBoard does too. Two
     * lines converging on a vehicle from parents charging different prices
     * would otherwise quote different figures on different boards.
     *
     * Nothing here asks whether the predecessor is researched. A cell earns its
     * figures by having a vehicle below it on the line, not by being the next
     * thing you could research — fragments are banked against a tank long
     * before it comes within reach.
     *
     * @param  array{is_purchased?: bool, is_unlocked?: bool}  $owned
     * @return array<string, mixed>
     */
    private function cell(WotVehicle $vehicle, ?WotVehicle $predecessor, ?WotTankPurchase $purchase, array $owned): array
    {
        $tier = (int) $vehicle->tier;
        $baseXp = (int) (($predecessor?->next_tanks ?? [])[$vehicle->tank_id] ?? 0);
        $built = (int) ($purchase?->blueprint_fragments ?? 0);

        $planned = $this->cost->plan($tier, (string) $vehicle->nation, (array) ($purchase?->blueprint_plan ?? []));

        return [
            'tank_id' => $vehicle->tank_id,
            'name' => $vehicle->short_name ?? $vehicle->name,
            'tier' => $tier,
            // The vehicle's own, not the row's. A line is one nation all the
            // way down, but the planner asks what a fragment costs *here*.
            //
            // The group it belongs to is not carried beside it: which nations
            // may pay is already spelled out, one per line, under 'planned'.
            'nation' => $vehicle->nation,
            // Undiscounted, which is what the cell prints: it is constant per
            // tank, so the column reads as what the tank is worth. What the
            // fragments have taken off it is xp_saved.
            'base_xp' => $baseXp,
            'fragments' => $built,
            'fragments_needed' => $this->cost->fragmentsNeeded($tier),
            'percent_per_fragment' => $this->cost->percentPerFragment($tier),
            'xp_saved' => $this->cost->xpSaved($tier, $baseXp, $built),
            'xp_remaining' => $this->cost->xpRemaining($tier, $baseXp, $built),
            /*
             * One line per nation that could pay for a fragment, plus what they
             * come to. The lines are the planner's rows; the totals are what
             * the grid cell prints and what plannedTotals() sums.
             */
            'planned' => $planned,
            'xp_after_plan' => $this->cost->xpRemaining($tier, $baseXp, $built + $planned['fragments']),
            /*
             * A starter vehicle is not researched from anything, so fragments
             * have nothing to discount. Shown as a dash rather than a zero you
             * could type into.
             */
            'is_researchable' => $predecessor !== null,
            // Already researched, so whatever you hold is spent or spare. Still
            // shown and still editable — a record of what was held is worth
            // keeping — but greyed, because it buys nothing now.
            'is_unlocked' => $owned['is_unlocked'] ?? false,
            'is_shared' => false,
            'shared_with' => null,
        ];
    }

    /**
     * Assign each vehicle to one row, so a tank on two lines is counted once.
     *
     * Simpler than the XP board's, which also had to claim the unlock leading
     * into each vehicle. Fragments are held against the vehicle itself, so
     * there is only the one thing to claim.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function claimShared(Collection $rows): Collection
    {
        $owner = [];

        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                $owner[$cell['tank_id']] ??= ['key' => $row['key'], 'name' => $row['name']];
            }
        }

        return $rows->map(function (array $row) use ($owner): array {
            foreach ($row['cells'] as $tier => $cell) {
                if ($owner[$cell['tank_id']]['key'] !== $row['key']) {
                    $row['cells'][$tier]['is_shared'] = true;
                    $row['cells'][$tier]['shared_with'] = $owner[$cell['tank_id']]['name'];
                }
            }

            $owned = collect($row['cells'])->reject(fn (array $c): bool => $c['is_shared']);

            $row['fragments'] = (int) $owned->sum('fragments');
            $row['planned_fragments'] = (int) $owned->sum(fn (array $c): int => $c['planned']['fragments']);
            // Researched cells are left out of this one alone: their fragments
            // are a record worth keeping, but the XP behind them is already
            // paid and a line total that counted it would owe money twice.
            $row['xp_remaining'] = (int) $owned
                ->reject(fn (array $c): bool => $c['is_unlocked'])
                ->sum('xp_remaining');

            return $row;
        });
    }

    /**
     * What every plan on the board comes to, in fragments and in blueprints.
     *
     * Over the cells each row owns, so a vehicle on two lines is counted once —
     * the same rule the fragment total follows.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{fragments: int, national_blueprints: int, universal_blueprints: int}
     */
    private function plannedTotals(Collection $rows): array
    {
        $planned = $rows
            ->flatMap(fn (array $r): array => $r['cells'])
            ->reject(fn (array $c): bool => $c['is_shared'])
            ->map(fn (array $c): array => $c['planned']);

        return [
            'fragments' => (int) $planned->sum('fragments'),
            'national_blueprints' => (int) $planned->sum('national_blueprints'),
            'universal_blueprints' => (int) $planned->sum('universal_blueprints'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    private function tierColumns(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $r): array => array_keys($r['cells']))
            ->unique()
            ->sort()
            ->map(fn ($tier): int => (int) $tier)
            ->values()
            ->all();
    }
}
