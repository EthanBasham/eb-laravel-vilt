<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;

/**
 * Assembles the grinding board.
 *
 * Five views over one dataset, mirroring the spreadsheet this replaced: what is
 * being played now, the XP left on every path, planned Free XP, the credits to
 * buy it all, and blueprint fragments held.
 *
 * Four of those are the tech tree, laid out one way or another, and each is its
 * own service. The fifth is this class's own work — and it is built from the XP
 * board's cells rather than from a second query, so a tank being ground and the
 * same tank on the tree can never quote different figures. That is the whole
 * reason the tracked-target tables are gone: they were the second query.
 */
class GrindBoard
{
    public function __construct(
        private readonly PurchaseBoard $purchases,
        private readonly FreeXpBoard $freeXp,
        private readonly XpBoard $xp,
        private readonly BlueprintBoard $blueprints,
        private readonly AccountProgress $progress,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(WotAccount $account): array
    {
        $settings = WotGrindSetting::firstOrNew(['wot_account_id' => $account->id]);

        // Credits are the purchase board's business alone, and planned Free XP
        // the Free XP board's, so the headline cards and the tabs they head can
        // never quote different figures.
        $purchase = $this->purchases->for($account);
        $freexp = $this->freeXp->for($account);
        $xp = $this->xp->for($account);
        $blueprints = $this->blueprints->for($account);

        $byTank = $this->cellsByTank($xp['rows']);
        $playing = $account->tankPurchases()->where('is_playing', true)->get()->keyBy('tank_id');
        $active = $this->active($playing, $byTank);

        return [
            'active' => $active,
            // The tanks you could add to that list: everything the tree knows
            // about and are not already grinding. Drawn from the same index, so
            // nothing addable can turn out to have no cell to read.
            'options' => $this->options($byTank, $playing, $this->progress->for($account)),
            'purchase' => $purchase,
            'freexp' => $freexp,
            'xp' => $xp,
            'blueprints' => $blueprints,
            'settings' => [
                /*
                 * Passed through as stored, null included — the client tells
                 * "never saved" from "saved as empty" by it, and only the
                 * former seeds the tier filter from bought_tiers.
                 */
                'purchase_filters' => $settings->purchase_filters,
                'freexp_filters' => $settings->freexp_filters,
                'xp_filters' => $settings->xp_filters,
                'blueprints_filters' => $settings->blueprints_filters,
            ],
            'totals' => $this->totals(
                $active,
                $purchase['credits_required'],
                $freexp['free_xp_planned'],
                $xp['xp_remaining'],
                $blueprints['blueprint_fragments'],
            ),
        ];
    }

    /**
     * Active Grinding alone, for a page that shows that table and nothing else.
     *
     * The XP board still has to be built — every figure on a row is read off
     * its cells — but the other three do not, and the dashboard has no use for
     * them. Same assembly as for(), so the two copies of the table cannot come
     * from two different reckonings.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function activeGrinding(WotAccount $account): array
    {
        $byTank = $this->cellsByTank($this->xp->for($account)['rows']);
        $playing = $account->tankPurchases()->where('is_playing', true)->get()->keyBy('tank_id');
        $active = $this->active($playing, $byTank);

        return ['rows' => $active, 'totals' => $this->activeTotals($active)];
    }

    /**
     * The XP board's cells, indexed by the tank each one is about.
     *
     * A tank appears on every line that runs through it, so this keeps two
     * things per tank: the cell from the row that *claims* it, which is the one
     * carrying its modules, and the unlocks from every row it appears on.
     *
     * The second is why a tier IX under a pair of tier Xs lists both — the two
     * are separate grinds and both are owed, and taking only the claiming row's
     * unlock would have hidden one of them behind display order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array{cell: array<string, mixed>, nation: ?string, unlocks: array<int, array<string, mixed>>}>
     */
    private function cellsByTank(array $rows): array
    {
        $byTank = [];

        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                $tankId = $cell['tank_id'];

                $byTank[$tankId] ??= ['cell' => $cell, 'nation' => $row['nation'], 'unlocks' => []];

                if (! $cell['is_shared']) {
                    $byTank[$tankId]['cell'] = $cell;
                    /*
                     * A line is one nation all the way down — ancestry never
                     * crosses — so the row's nation is the tank's, and reading
                     * it here saves loading every vehicle again.
                     */
                    $byTank[$tankId]['nation'] = $row['nation'];
                }

                // Keyed by the tank being unlocked, so two rows that agree
                // about an unlock contribute it once.
                if ($cell['unlocks'] !== null && ! $cell['unlocks']['is_unlocked']) {
                    $byTank[$tankId]['unlocks'][$cell['unlocks']['tank_id']] = [
                        'tank_id' => $cell['unlocks']['tank_id'],
                        'name' => $cell['unlocks']['name'],
                        'xp' => (int) $cell['unlocks']['xp'],
                    ];
                }
            }
        }

        return $byTank;
    }

    /**
     * The tanks currently being played — the spreadsheet's Active Grinding.
     *
     * Membership of this list is the whole of what "playing it" means: you add
     * a tank when you start grinding it and drop it when you stop, and there is
     * no separate tick anywhere for the same fact.
     *
     * @param  Collection<int, WotTankPurchase>  $playing
     * @param  array<int, array<string, mixed>>  $byTank
     * @return list<array<string, mixed>>
     */
    private function active(Collection $playing, array $byTank): array
    {
        return $playing
            // A tank the tree has no cell for cannot be costed, and the picker
            // only ever offers tanks that have one.
            ->filter(fn (WotTankPurchase $tank): bool => isset($byTank[$tank->tank_id]))
            ->map(fn (WotTankPurchase $tank): array => $this->activeRow($tank, $byTank[$tank->tank_id]))
            // Tech-tree nation order, then tier and name within a nation — the
            // way the garage itself is scanned.
            ->sortBy(fn (array $row): array => [WotVehicle::rankOf($row['nation']), -$row['tier'], $row['name']])
            ->values()
            ->all();
    }

    /**
     * Everything a tank still owes: its own modules, plus every unlock it leads
     * to that is not researched yet.
     *
     * Summed rather than narrowed to the cheapest branch, so the unlocks a row
     * lists and the total beside them always agree — the cell shows the parts,
     * and this is what they come to.
     *
     * @param  array{cell: array<string, mixed>, unlocks: array<int, array<string, mixed>>}  $entry
     */
    private function owed(array $entry): int
    {
        return (int) $entry['cell']['module_xp'] + (int) collect($entry['unlocks'])->sum('xp');
    }

    /**
     * @param  array{cell: array<string, mixed>, nation: ?string, unlocks: array<int, array<string, mixed>>}  $entry
     * @return array<string, mixed>
     */
    private function activeRow(WotTankPurchase $tank, array $entry): array
    {
        $cell = $entry['cell'];
        $unlocks = array_values($entry['unlocks']);
        $researchCost = (int) collect($unlocks)->sum('xp');
        $required = $this->owed($entry);
        $banked = (int) $tank->banked_xp;

        return [
            'tank_id' => $cell['tank_id'],
            'name' => $cell['name'],
            'tier' => $cell['tier'],
            'nation' => $entry['nation'],
            // The one number no API can supply.
            'banked_xp' => $banked,
            // Named as the module picker's prop expects: the same component
            // serves this table and the XP board, against the same store.
            'module_xp' => (int) $cell['module_xp'],
            'module_xp_total' => (int) $cell['module_xp_total'],
            'modules' => $cell['modules'],
            'unlocks' => $unlocks,
            'research_cost' => $researchCost,
            'xp_required' => $required,
            'xp_remaining' => max(0, $required - $banked),
            'progress' => $this->progress($banked, $required),
        ];
    }

    /**
     * The tanks that can be added to Active Grinding.
     *
     * Read from the vehicles rather than from the cells, which carry neither a
     * short name nor a type: the picker is a list you scan by eye, wearing the
     * flag, tier and type badge every other vehicle list here wears, and the
     * board's cells have no reason to carry two more fields for its sake.
     *
     * It also says which of them are worth offering unprompted: the picker
     * opens on tanks in the garage that still owe XP, which is the shape of
     * "something I could grind next" and a far shorter list than the tree.
     *
     * @param  array<int, array<string, mixed>>  $byTank
     * @param  Collection<int, WotTankPurchase>  $playing
     * @param  array<int, array{is_purchased: bool, is_unlocked: bool}>  $progress
     * @return list<array<string, mixed>>
     */
    private function options(array $byTank, Collection $playing, array $progress): array
    {
        return WotVehicle::whereIn('tank_id', array_keys($byTank))
            ->get(['tank_id', 'name', 'short_name', 'tier', 'nation', 'type'])
            ->reject(fn (WotVehicle $vehicle): bool => $playing->has($vehicle->tank_id))
            ->map(fn (WotVehicle $vehicle): array => [
                'tank_id' => $vehicle->tank_id,
                // short_name, like every other vehicle list here: "Obj. 279 (e)"
                // against "Object 279 early".
                'name' => $vehicle->short_name ?? $vehicle->name,
                'tier' => $vehicle->tier,
                'nation' => $vehicle->nation,
                'type' => $vehicle->type,
                // Unioned across every line, like the lock on Tanks to
                // Purchase: owning a tank is a fact about the tank.
                'is_purchased' => $progress[$vehicle->tank_id]['is_purchased'] ?? false,
                'xp_remaining' => $this->owed($byTank[$vehicle->tank_id]),
            ])
            // Same tech-tree nation order as every other vehicle list here.
            ->sortBy(fn (array $option): array => [WotVehicle::rankOf($option['nation']), -$option['tier'], $option['name']])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $active
     * @return array<string, mixed>
     */
    private function totals(
        array $active,
        int $creditsRequired,
        int $freeXpPlanned,
        int $xpRemaining,
        int $blueprintFragments,
    ): array {
        return [
            // Nested rather than a sibling prop: every partial reload on this
            // page already asks for 'totals', so the Active Grinding total row
            // cannot go stale after an edit without someone remembering to add
            // a new key to three separate only: lists.
            'active' => $this->activeTotals($active),
            /*
             * The tree's figures. A board that shows the whole tree and a
             * card that totalled something narrower were two answers to one
             * question, and the card is the one people read.
             */
            'xp_remaining' => $xpRemaining,
            'free_xp_planned' => $freeXpPlanned,
            'credits_required' => $creditsRequired,
            'blueprint_fragments' => $blueprintFragments,
            'banked_xp' => (int) collect($active)->sum('banked_xp'),
        ];
    }

    /**
     * Column sums for the Active Grinding table's total row.
     *
     * @param  list<array<string, mixed>>  $active
     * @return array<string, mixed>
     */
    private function activeTotals(array $active): array
    {
        $rows = collect($active);
        $required = (int) $rows->sum('xp_required');

        return [
            'tanks' => $rows->count(),
            'banked_xp' => (int) $rows->sum('banked_xp'),
            'module_xp' => (int) $rows->sum('module_xp'),
            'research_cost' => (int) $rows->sum('research_cost'),
            'xp_required' => $required,
            'xp_remaining' => (int) $rows->sum('xp_remaining'),
            'progress' => $this->progress((int) $rows->sum('banked_xp'), $required),
        ];
    }

    /**
     * How far a grind has got, as a percentage.
     *
     * Recomputed from the summed required and banked rather than averaged
     * across rows: averaging would let a 4,000 XP module grind pull as hard on
     * the figure as a 400,000 XP tier X.
     */
    private function progress(int $banked, int $required): float
    {
        if ($required <= 0) {
            return 100.0;
        }

        return round(min(100, $banked / $required * 100), 1);
    }
}
