<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;

/**
 * The Blueprints view: fragments held, and nothing else.
 *
 * The last tab to become the tree itself, and the simplest of the four. A cell
 * is one number — how many fragments you hold towards researching that vehicle
 * — typed by hand, because the encyclopedia publishes neither the fragments a
 * vehicle needs nor the discount they buy.
 *
 * It stays reference only. What the fragments actually reduce the cost to is
 * recorded on the XP Remaining board, against the same vehicle; deriving one
 * from the other would mean reverse-engineering a curve that silently rots on
 * the next rebalance.
 */
class BlueprintBoard
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
            'blueprint_fragments' => (int) $rows->sum('fragments'),
        ];
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
                    ->map(fn (WotVehicle $v, int $tier): array => $this->cell(
                        $v,
                        // The vehicle below it on this line. Where there is
                        // none, this is the root of the tree and there is
                        // nothing to research — so nothing to blueprint.
                        $byTier->has($tier - 1),
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
     * @param  array{is_purchased?: bool, is_unlocked?: bool}  $owned
     * @return array<string, mixed>
     */
    private function cell(WotVehicle $vehicle, bool $isResearchable, ?WotTankPurchase $purchase, array $owned): array
    {
        return [
            'tank_id' => $vehicle->tank_id,
            'name' => $vehicle->short_name ?? $vehicle->name,
            'tier' => $vehicle->tier,
            'fragments' => (int) ($purchase?->blueprint_fragments ?? 0),
            /*
             * A starter vehicle is not researched from anything, so fragments
             * have nothing to discount. Shown as a dash rather than a zero you
             * could type into.
             */
            'is_researchable' => $isResearchable,
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

            $row['fragments'] = (int) collect($row['cells'])
                ->reject(fn (array $c): bool => $c['is_shared'])
                ->sum('fragments');

            return $row;
        });
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
