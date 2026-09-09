<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\WotExpectedValue;

/**
 * Refreshes XVM's WN8 expected-value table.
 *
 * Not part of the Wargaming API and not covered by WargamingClient — this is a
 * static file on XVM's CDN, with no application id, no envelope and no rate
 * limit, so it uses the HTTP client directly.
 */
class SyncExpectedValues extends Command
{
    protected $signature = 'wot:sync-expected-values';

    protected $description = 'Refresh the WN8 expected-value table from XVM';

    private const FEED = 'https://static.modxvm.com/wn8-data-exp/json/wn8exp.json';

    public function handle(): int
    {
        $this->info('Fetching WN8 expected values from XVM...');

        $response = Http::timeout(30)->acceptJson()->get(self::FEED);

        if ($response->failed()) {
            $this->error("XVM returned HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $payload = $response->json();
        $rows = $payload['data'] ?? [];
        $version = $payload['header']['version'] ?? null;

        if ($rows === []) {
            $this->error('The feed contained no expected values; leaving the existing table alone.');

            return self::FAILURE;
        }

        // upsert rather than truncate-and-insert: a partial failure mid-write
        // would otherwise leave the table empty and silently disable WN8
        // everywhere until the next successful run.
        WotExpectedValue::upsert(
            collect($rows)->map(fn (array $row): array => [
                'tank_id' => $row['IDNum'],
                'exp_damage' => $row['expDamage'],
                'exp_spot' => $row['expSpot'],
                'exp_frag' => $row['expFrag'],
                'exp_def' => $row['expDef'],
                'exp_win_rate' => $row['expWinRate'],
                'version' => $version,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
            ['tank_id'],
            ['exp_damage', 'exp_spot', 'exp_frag', 'exp_def', 'exp_win_rate', 'version', 'updated_at'],
        );

        $this->info('Stored '.count($rows)." expected values (XVM version {$version}).");

        return self::SUCCESS;
    }
}
