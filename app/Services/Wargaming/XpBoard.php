<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotTankModule;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;
use App\Models\WotVehicleModule;

/**
 * The XP Remaining view: research XP, and nothing else.
 *
 * Laid out like Tanks to Purchase and Free XP — the whole tree, one row per
 * line, one column per tier — and a cell answers exactly two questions about
 * the vehicle at that tier: what it costs to unlock the next one, and what its
 * own modules still cost. No credits, no banked XP, no Free XP; those are other
 * tabs' business and quoting them here would make this one read twice.
 *
 * Both figures count down. An unlock is settled once the vehicle it leads to is
 * researched — decided by LineOwnership, so this board and the purchase board
 * can never disagree about which tanks you have — and module XP drops as
 * modules are ticked researched.
 */
class XpBoard
{
    public function __construct(
        private readonly TechTreeLines $lines,
        private readonly AccountProgress $progress,
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
            /*
             * Summed over the rows, so the tab's footer and the headline card
             * are one figure. Each vehicle belongs to exactly one row after
             * claimShared(), so a tank on two lines is counted once.
             */
            'xp_remaining' => (int) $rows->sum('xp_remaining'),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(WotAccount $account): Collection
    {
        $lines = $this->lines->lines((int) config('wargaming.line_min_tier'));

        $tankIds = $lines->flatMap(fn (array $l): array => $l['vehicles']->pluck('tank_id')->all())->unique();

        // Upgrades only, unlike the Free XP board: nothing here walks the
        // research graph, so the stock modules that root it are not needed and
        // would only have to be filtered out again.
        $modules = WotVehicleModule::whereIn('tank_id', $tankIds)
            ->onlyUpgrades()
            ->orderByDesc('price_xp')
            ->get()
            ->groupBy('tank_id');

        $purchases = $account->tankPurchases()->get()->keyBy('tank_id');
        $tankModules = WotTankModule::where('wot_account_id', $account->id)->get()->keyBy('tank_id');

        // Account-wide rather than per line: owning a tank is a fact about the
        // tank, so the same vehicle cannot be settled on one row and open on
        // another.
        $owned = $this->progress->for($account);

        return $lines->map(function (array $line) use ($modules, $purchases, $owned, $tankModules): array {
            $byTier = $line['vehicles'];

            $cells = $byTier
                ->map(fn (WotVehicle $v, int $tier): array => $this->cell(
                    $v,
                    // The next vehicle *on this line*, which is what the column
                    // to the right shows. A tier X that branches has one next
                    // tank per branch, and each branch is its own row.
                    $byTier->get($tier + 1),
                    $modules->get($v->tank_id) ?? collect(),
                    $purchases,
                    $owned,
                    $tankModules->get($v->tank_id),
                ))
                ->sortBy('tier')
                ->values();

            return [
                ...collect($line)->except('vehicles')->all(),
                'cells' => $cells->keyBy('tier')->all(),
                // xp_remaining is not set here: it depends on which cells this
                // row owns, which claimShared() decides.
            ];
        });
    }

    /**
     * @param  Collection<int, WotVehicleModule>  $modules
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  array<int, array{is_purchased: bool, is_unlocked: bool, modules_researched: bool}>  $owned
     * @return array<string, mixed>
     */
    private function cell(
        WotVehicle $vehicle,
        ?WotVehicle $next,
        Collection $modules,
        Collection $purchases,
        array $owned,
        ?WotTankModule $tankModule,
    ): array {
        /*
         * Unlocking what a vehicle leads to is done from that vehicle, so a
         * player who got there took its modules on the way. That is the
         * default; a tick either way overrides it.
         */
        $default = $owned[$vehicle->tank_id]['modules_researched'] ?? false;

        $options = $modules->map(fn (WotVehicleModule $m): array => [
            'module_id' => $m->module_id,
            'name' => $m->name,
            'slot' => $m->slot(),
            'price_xp' => (int) $m->price_xp,
            'is_researched' => WotTankModule::isResearched($tankModule, $m->module_id, $default),
        ])->values();

        return [
            'tank_id' => $vehicle->tank_id,
            'name' => $vehicle->name,
            'tier' => $vehicle->tier,
            'unlocks' => $next === null ? null : $this->unlocks($vehicle, $next, $purchases, $owned),
            'modules' => $options->all(),
            'module_xp' => (int) $options->where('is_researched', false)->sum('price_xp'),
            'module_xp_total' => (int) $options->sum('price_xp'),
            /*
             * Filled in by claimShared(). Seeded so every cell has the same
             * shape whether it ends up shared or not — the client reads these
             * on every cell, and a missing key would read as undefined.
             */
            'is_shared' => false,
            'shared_with' => null,
        ];
    }

    /**
     * What it costs to unlock the next vehicle on the line.
     *
     * The discount is stored against the vehicle being unlocked rather than the
     * one you play to unlock it, mirroring price_credit. Two lines converging
     * on the same tank then share one figure, which is right: fragments are
     * held against a tank, not against a route to it.
     *
     * @param  Collection<int, WotTankPurchase>  $purchases
     * @param  array<int, array{is_purchased: bool, is_unlocked: bool, modules_researched: bool}>  $owned
     * @return array<string, mixed>
     */
    private function unlocks(WotVehicle $vehicle, WotVehicle $next, Collection $purchases, array $owned): array
    {
        $purchase = $purchases->get($next->tank_id);
        $full = (int) (($vehicle->next_tanks ?? [])[$next->tank_id] ?? 0);
        $discounted = $purchase?->research_xp;

        return [
            'tank_id' => $next->tank_id,
            'name' => $next->short_name ?? $next->name,
            'xp' => (int) ($discounted ?? $full),
            'full_xp' => $full,
            // Null is "no discount recorded", which is distinct from a recorded
            // zero — you can hold fragments enough to unlock outright.
            'is_discounted' => $discounted !== null && (int) $discounted !== $full,
            // Researched already, so nothing is owed however much it lists at.
            'is_unlocked' => $owned[$next->tank_id]['is_unlocked'] ?? false,
            /*
             * Bought, so its research state follows the purchase and is not
             * this board's to un-tick: the lock renders flat and Tanks to
             * Purchase is where that is undone. Read from the same union
             * is_unlocked is, or one tank could read researched on one row and
             * unbought on another.
             */
            'is_purchased' => $owned[$next->tank_id]['is_purchased'] ?? false,
            // Filled in by claimShared(), like the cell's own flag.
            'is_shared' => false,
        ];
    }

    /**
     * Assign each vehicle to one row, and total what that row still owes.
     *
     * A vehicle sits on more than one line and is researched once. The first
     * row to show it in display order keeps the controls and counts its module
     * XP; the rest carry it read-only, naming where it lives.
     *
     * An unlock is claimed separately, and by the *next* tank rather than by
     * the cell showing it. Two lines diverging from one vehicle owe two
     * different unlocks — the tier IX under a pair of tier Xs unlocks both, and
     * both have to be researched — so treating the unlock as part of the shared
     * vehicle silently dropped one of them. Keying it on the tank being
     * unlocked makes the row that owns that tank pay for reaching it, which is
     * also what stops a converging pair charging twice.
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

                if ($cell['unlocks'] !== null) {
                    $row['cells'][$tier]['unlocks']['is_shared'] =
                        $owner[$cell['unlocks']['tank_id']]['key'] !== $row['key'];
                }
            }

            $row['xp_remaining'] = (int) collect($row['cells'])
                ->sum(fn (array $c): int => $this->cellXp($c));

            return $row;
        });
    }

    /**
     * What one cell still owes this row: the unlock ahead of it, plus its own
     * modules — each counted only where this row is the one that pays.
     *
     * @param  array<string, mixed>  $cell
     */
    private function cellXp(array $cell): int
    {
        $unlock = $cell['unlocks'] !== null
            && ! $cell['unlocks']['is_shared']
            && ! $cell['unlocks']['is_unlocked']
                ? $cell['unlocks']['xp']
                : 0;

        return (int) $unlock + ($cell['is_shared'] ? 0 : (int) $cell['module_xp']);
    }

    /**
     * Every tier holding a cell gets a column.
     *
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
