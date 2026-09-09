<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Models\WotAccount;
use App\Models\WotSnapshot;
use App\Models\WotVehicleSnapshot;
use App\Services\Wargaming\WargamingClient;
use App\Services\Wargaming\WargamingException;

/**
 * Captures every linked account's current lifetime totals.
 *
 * This is the only source of period statistics. The Wargaming API returns
 * lifetime figures and nothing else, so "last 7 days" can only ever be the
 * difference between two captures — which means history accumulates forward
 * from the first run and cannot be backfilled.
 *
 * Scheduled hourly. More frequent captures buy finer period boundaries and a
 * more accurate "last 1000 battles"; they cost one API call per account.
 */
class Snapshot extends Command
{
    protected $signature = 'wot:snapshot {--account= : Limit to one Wargaming account id}';

    protected $description = 'Capture current stats for linked accounts, building the history period stats are derived from';

    public function handle(WargamingClient $client): int
    {
        $accounts = WotAccount::query()
            ->when($this->option('account'), fn ($query, $id) => $query->where('account_id', $id))
            ->get();

        if ($accounts->isEmpty()) {
            $this->comment('No linked accounts to snapshot.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            try {
                $this->capture($client, $account);
            } catch (WargamingException $e) {
                $this->error("{$account->nickname}: {$e->getMessage()}");

                if ($e->isInvalidAccessToken()) {
                    $account->forgetToken();
                }

                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
    private function capture(WargamingClient $client, WotAccount $account): void
    {
        $capturedAt = now();

        $info = $client->accountInfo([$account->account_id], $account->access_token);
        $statistics = $info[(string) $account->account_id]['statistics']['all'] ?? [];

        if ($statistics === []) {
            $this->warn("{$account->nickname}: no statistics returned; skipping.");

            return;
        }

        $battles = (int) ($statistics['battles'] ?? 0);
        $latest = $account->snapshots()->latest('captured_at')->first();

        // Nothing has been played since the last capture, so a new row would be
        // an identical duplicate. Skipped to keep the table proportional to
        // activity rather than to uptime.
        if ($latest && $latest->battles === $battles) {
            $this->line("  {$account->nickname}: no new battles since ".$latest->captured_at->diffForHumans());

            return;
        }

        WotSnapshot::create([
            'wot_account_id' => $account->id,
            'captured_at' => $capturedAt,
            'battles' => $battles,
            'statistics' => $statistics,
        ]);

        $changed = $this->captureVehicles($client, $account, $capturedAt);

        $this->info("  {$account->nickname}: {$battles} battles, {$changed} vehicles changed.");
    }

    /**
     * Writes a row only for vehicles whose battle count moved since their last
     * recorded state. A player touches a handful of tanks out of several
     * hundred owned, so this keeps the table proportional to what was actually
     * played.
     */
    private function captureVehicles(WargamingClient $client, WotAccount $account, \DateTimeInterface $capturedAt): int
    {
        $rows = $client->tankStats($account->account_id, $account->access_token);

        // The most recent known battle count per tank, in one query rather than
        // one per vehicle.
        $previous = WotVehicleSnapshot::query()
            ->where('wot_account_id', $account->id)
            ->orderByDesc('captured_at')
            ->get(['tank_id', 'battles', 'captured_at'])
            ->groupBy('tank_id')
            ->map(fn ($group) => $group->first()->battles);

        $pending = [];

        foreach ($rows[(string) $account->account_id] ?? [] as $row) {
            $tankId = $row['tank_id'] ?? null;
            $stats = $row['all'] ?? [];
            $battles = (int) ($stats['battles'] ?? 0);

            if (! $tankId || $battles === 0 || ($previous[$tankId] ?? null) === $battles) {
                continue;
            }

            $pending[] = [
                'wot_account_id' => $account->id,
                'tank_id' => $tankId,
                'captured_at' => $capturedAt,
                'battles' => $battles,
                'statistics' => json_encode($stats),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($pending, 200) as $chunk) {
            WotVehicleSnapshot::insert($chunk);
        }

        return count($pending);
    }
}
