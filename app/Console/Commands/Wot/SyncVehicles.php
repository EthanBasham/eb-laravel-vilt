<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Models\WotVehicle;
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
                        'is_gift' => (bool) ($vehicle['is_gift'] ?? false),
                        // Null rather than an empty array for vehicles outside
                        // the tech tree, so "has no research line" and "has an
                        // empty one" stay distinguishable.
                        'next_tanks' => $vehicle['next_tanks'] ?: null,
                        'modules_tree' => $vehicle['modules_tree'] ?: null,
                    ],
                );
            }

            $seen += count($vehicles);
            $this->line("  page {$page}: ".count($vehicles).' vehicles');
            $page++;
        } while (count($vehicles) === 100);

        $this->info("Synced {$seen} vehicles.");

        return self::SUCCESS;
    }
}
