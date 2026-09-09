<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotModulePlan;
use App\Models\WotVehicleModule;

/**
 * The Free XP view: modules, and nothing else.
 *
 * Laid out like Tanks to Purchase — one row per research line, one column per
 * tier, one cell per vehicle — because it answers the same shape of question
 * against the same tree. Where that board asks "what do I still have to pay",
 * this one asks "which modules am I buying with Free XP", and a cell is a list
 * of the vehicle's upgrade modules rather than a price.
 *
 * There is no discount structure here and no owned/unowned state: a module is
 * planned or it is not. That makes this the simpler of the two boards, and the
 * absence is deliberate rather than unfinished.
 */
class FreeXpBoard
{
    public function __construct(private readonly TechTreeLines $lines) {}

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
             * Summed over the rows rather than over the plans directly, so the
             * tab's footer and the headline card can never quote different
             * figures. Each vehicle belongs to exactly one row after
             * claimShared(), so a tank on two lines is counted once.
             */
            'free_xp_planned' => (int) $rows->sum('planned_xp'),
        ];
    }

    /**
     * One row per line, each vehicle carrying its upgrade modules.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(WotAccount $account): Collection
    {
        $lines = $this->lines->lines((int) config('wargaming.line_min_tier'));

        /*
         * Every module on the board in one query, rather than one query per
         * vehicle. 67 lines of up to eleven tiers each is several hundred
         * vehicles, and moduleOptions() on the grind step does it per step —
         * which is fine for the dozen steps of a path and would be hundreds of
         * round trips here.
         */
        // pluck, not keys: a line's vehicles are keyed by tier, not by tank id.
        $tankIds = $lines->flatMap(fn (array $l): array => $l['vehicles']->pluck('tank_id')->all())->unique();
        $modules = $this->modulesFor($tankIds->all());
        $plans = $this->plans($account);

        return $lines->map(function (array $line) use ($modules, $plans): array {
            $cells = $line['vehicles']
                ->map(fn ($v): array => $this->cell(
                    $v->tank_id,
                    $v->name,
                    $v->tier,
                    $modules->get($v->tank_id) ?? collect(),
                    $plans->get($v->tank_id)?->module_ids ?? [],
                ))
                ->sortBy('tier')
                ->values();

            return [
                ...collect($line)->except('vehicles')->all(),
                'cells' => $cells->keyBy('tier')->all(),
                // planned_xp is not set here: it depends on which cells this row
                // actually owns, which claimShared() decides.
            ];
        });
    }

    /**
     * @param  Collection<int, WotVehicleModule>  $modules
     * @param  list<int>  $plannedIds
     * @return array<string, mixed>
     */
    private function cell(int $tankId, string $name, int $tier, Collection $modules, array $plannedIds): array
    {
        $planned = array_flip($plannedIds);

        $options = $modules->map(fn (WotVehicleModule $m): array => [
            'module_id' => $m->module_id,
            'name' => $m->name,
            'slot' => $m->slot(),
            'price_xp' => (int) $m->price_xp,
            'is_planned' => isset($planned[$m->module_id]),
        ])->values();

        return [
            'tank_id' => $tankId,
            'name' => $name,
            'tier' => $tier,
            'modules' => $options->all(),
            'planned_xp' => (int) $options->where('is_planned', true)->sum('price_xp'),
            /*
             * The whole cost of maxing the vehicle, planned or not. Shown as
             * the dropdown's subtitle so a cell reads as "none of 214,000"
             * rather than just "0" — a vehicle with nothing planned and a
             * vehicle with no modules at all are different states.
             */
            'total_xp' => (int) $options->sum('price_xp'),
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
     * Assign each vehicle to one row, and total what that row plans.
     *
     * A vehicle sits on more than one line — twelve Soviet lines share the MS-1
     * — and its modules are bought once. The first row to show it in display
     * order keeps the dropdown and counts the XP; the rest carry it read-only,
     * naming where it lives.
     *
     * One pass, unlike the purchase board's two. There the row that still owed
     * for a vehicle had to get first refusal, because a settled row claiming it
     * would drop the price off the board; here every row reads the same plan
     * for a given tank, so first-on-screen is the whole rule.
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

            $row['planned_xp'] = (int) collect($row['cells'])
                ->reject(fn (array $c): bool => $c['is_shared'])
                ->sum('planned_xp');

            return $row;
        });
    }

    /**
     * Every tier holding a cell gets a column.
     *
     * No bought_tiers counterpart: the purchase board hides a tier that is
     * settled, and a module plan is never settled — an untouched tier is the
     * normal state of one you have not thought about yet, not noise.
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

    /**
     * @param  list<int>  $tankIds
     * @return Collection<int, Collection<int, WotVehicleModule>>
     */
    private function modulesFor(array $tankIds): Collection
    {
        return WotVehicleModule::whereIn('tank_id', $tankIds)
            ->onlyUpgrades()
            // Dearest first, which is the order they are worth Free XP in.
            ->orderByDesc('price_xp')
            ->get()
            ->groupBy('tank_id');
    }

    /**
     * @return Collection<int, WotModulePlan>
     */
    private function plans(WotAccount $account): Collection
    {
        return WotModulePlan::where('wot_account_id', $account->id)->get()->keyBy('tank_id');
    }
}
