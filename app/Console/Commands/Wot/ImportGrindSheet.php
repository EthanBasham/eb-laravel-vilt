<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotVehicleSnapshot;
use App\Services\Wargaming\TechTree;

/**
 * Seeds the grinding board from the original spreadsheet.
 *
 * The source is docs/WOT Stat Trackers.xlsx, flattened to
 * database/seeders/data/grind-sheet.json — a JSON fixture rather than the
 * workbook itself, so no spreadsheet-reading dependency has to ship just to run
 * a one-off import. The extraction and its name-matching are documented in
 * docs/setup-log.md.
 *
 * Idempotent: re-running replaces each target's steps rather than duplicating.
 */
class ImportGrindSheet extends Command
{
    protected $signature = 'wot:import-grind-sheet {--account= : Wargaming account id, if more than one is linked}';

    protected $description = 'Seed the grinding board from the exported spreadsheet data';

    public function handle(TechTree $tree): int
    {
        $account = WotAccount::query()
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->first();

        if (! $account) {
            $this->error('No linked Wargaming account to import against.');

            return self::FAILURE;
        }

        $path = database_path('seeders/data/grind-sheet.json');

        if (! is_file($path)) {
            $this->error("Missing {$path}.");

            return self::FAILURE;
        }

        $sheet = json_decode(file_get_contents($path), true);

        // "Owned" comes from the snapshot history rather than a live API call:
        // every vehicle ever played has rows there, and a seeding command
        // shouldn't depend on Wargaming being reachable.
        $owned = WotVehicleSnapshot::where('wot_account_id', $account->id)
            ->distinct()
            ->pluck('tank_id')
            ->all();

        $this->line('  '.count($owned).' vehicles owned, used to truncate each path');

        $banked = collect($sheet['active'])->keyBy('tank_id');
        $imported = 0;

        foreach ($sheet['targets'] as $order => $row) {
            $target = WotGrindTarget::updateOrCreate(
                ['wot_account_id' => $account->id, 'tank_id' => $row['tank_id']],
                ['sort_order' => $order],
            );

            // Replaced wholesale: the path can change when a vehicle is bought,
            // and merging would leave steps for tiers no longer on the route.
            $target->steps()->delete();

            $steps = $tree->pathTo($row['tank_id'], $owned);

            /*
             * Trim to the tiers the spreadsheet actually covers.
             *
             * "Owned" is inferred from snapshot history, which records what has
             * been *played* — a good proxy, but the sheet knows what has been
             * *researched*, and researched-but-unplayed vehicles made the
             * auto-path walk back too far. Where the sheet has an opinion it
             * wins, because it is the record of real progress; targets added
             * later in the app get the full inferred path.
             */
            $lowestTier = collect(array_keys($row['tiers']))->map(fn ($t): int => (int) $t)->min();

            if ($lowestTier !== null) {
                $steps = array_values(array_filter($steps, fn (array $s): bool => $s['tier'] >= $lowestTier));
            }

            foreach ($steps as $position => $step) {
                $tier = $row['tiers'][(string) $step['tier']] ?? $row['tiers'][$step['tier']] ?? null;
                $active = $banked->get($step['tank_id']);

                WotGrindStep::create([
                    'wot_grind_target_id' => $target->id,
                    'tank_id' => $step['tank_id'],
                    'tier' => $step['tier'],
                    'position' => $position,
                    'research_xp' => $step['research_xp'],
                    /*
                     * The sheet's figure is post-blueprint, which is exactly
                     * what research_xp_remaining means.
                     *
                     * A tier the sheet mentions but gives no research figure
                     * for is one already researched — zero, not the API's full
                     * price. Leaving it null would fall back to that price and
                     * re-charge for an unlock long since paid.
                     */
                    'research_xp_remaining' => $tier === null ? null : ($tier['research'] ?? 0),
                    'module_xp_remaining' => $tier['module'] ?? 0,
                    'free_xp_planned' => $tier['free_xp'] ?? 0,
                    'blueprint_fragments' => $tier['fragments'] ?? 0,
                    'banked_xp' => $active['banked_xp'] ?? 0,
                    'is_active' => $active !== null,
                    'price_credit' => $step['price_credit'],
                ]);
            }

            $imported++;
            $this->line(sprintf('  %-26s %2d steps', $row['name'], count($steps)));
        }

        WotGrindSetting::updateOrCreate(
            ['wot_account_id' => $account->id],
            [
                'credits_available' => $sheet['settings']['credits_available'] ?? 0,
                'garage_slots_vacant' => $sheet['settings']['garage_slots_vacant'] ?? 0,
            ],
        );

        $this->info("Imported {$imported} targets.");

        return self::SUCCESS;
    }
}
