<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Models\WotVehicle;
use App\Models\WotVehicleModule;
use App\Services\Wargaming\WargamingClient;
use App\Services\Wargaming\WargamingException;

class SyncVehicles extends Command
{
    protected $signature = 'wot:sync-vehicles';

    protected $description = 'Refresh the local copy of the World of Tanks vehicle encyclopedia';

    public function handle(WargamingClient $client): int
    {
        $this->info('Fetching the vehicle encyclopedia...');

        $page = 1;
        $seen = 0;

        do {
            try {
                // The endpoint caps `limit` at 100, so this pages until a short
                // page comes back rather than assuming a vehicle count.
                $vehicles = $client->vehicles($page);
            } catch (WargamingException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            foreach ($vehicles as $vehicle) {
                WotVehicle::updateOrCreate(
                    ['tank_id' => $vehicle['tank_id']],
                    [
                        'name' => $vehicle['name'],
                        'short_name' => $vehicle['short_name'] ?? null,
                        'tier' => $vehicle['tier'],
                        'nation' => $vehicle['nation'],
                        'type' => $vehicle['type'],
                        'is_premium' => (bool) ($vehicle['is_premium'] ?? false),
                        'image_url' => $vehicle['images']['small_icon'] ?? null,
                        // Null rather than [] outside the tech tree, so "no
                        // research line" and "an empty one" stay distinct.
                        'next_tanks' => $vehicle['next_tanks'] ?: null,
                        'price_credit' => $vehicle['price_credit'] ?? null,
                    ],
                );
            }

            $this->storeModules($vehicles);

            $seen += count($vehicles);
            $this->line("  page {$page}: ".count($vehicles).' vehicles');
            $page++;
        } while (count($vehicles) === 100);

        $this->info("Synced {$seen} vehicles, ".WotVehicleModule::count().' modules.');

        return self::SUCCESS;
    }

    /**
     * Flattens each vehicle's modules_tree into rows.
     *
     * upsert rather than delete-then-insert so a partial failure mid-sync can't
     * leave the board with no modules to tick — the grind view would silently
     * report every vehicle as fully upgraded.
     *
     * @param  array<string, mixed>  $vehicles
     */
    private function storeModules(array $vehicles): void
    {
        $rows = [];

        foreach ($vehicles as $vehicle) {
            foreach ($vehicle['modules_tree'] ?? [] as $module) {
                $rows[] = [
                    'module_id' => $module['module_id'],
                    'tank_id' => $vehicle['tank_id'],
                    'name' => $module['name'],
                    'type' => $module['type'],
                    'price_xp' => $module['price_xp'] ?? 0,
                    'price_credit' => $module['price_credit'] ?? 0,
                    'is_default' => (bool) ($module['is_default'] ?? false),
                    // What researching this one opens up. Stored as sent —
                    // forward-pointing — and inverted where it is read.
                    'next_modules' => json_encode(array_values($module['next_modules'] ?? [])),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            // Matched on the pair, since a module id repeats across vehicles.
            WotVehicleModule::upsert(
                $chunk,
                ['tank_id', 'module_id'],
                ['name', 'type', 'price_xp', 'price_credit', 'is_default', 'next_modules', 'updated_at'],
            );
        }
    }
}
