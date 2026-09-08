<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Services\Wargaming\WargamingClient;
use App\Services\Wargaming\WargamingException;

/**
 * Answers "is the Wargaming API actually reachable with the configured
 * credentials" without needing a linked account or a browser.
 */
class Ping extends Command
{
    protected $signature = 'wot:ping {nickname? : A player nickname to look up as a live check}';

    protected $description = 'Check that the configured Wargaming application ID works';

    public function handle(WargamingClient $client): int
    {
        $realm = config('wargaming.realm');
        $this->line("Realm: {$realm} (".config("wargaming.hosts.{$realm}").')');

        try {
            $accounts = $client->searchAccounts($this->argument('nickname') ?? 'jack', 3);
        } catch (WargamingException $e) {
            $this->error($e->getMessage());

            if ($e->isInvalidIpAddress()) {
                $this->newLine();
                $this->comment('This is a configuration issue at Wargaming, not in this codebase.');
            }

            return self::FAILURE;
        }

        $this->info('Wargaming API reachable. Sample results:');

        foreach ($accounts as $account) {
            $this->line("  {$account['account_id']}  {$account['nickname']}");
        }

        return self::SUCCESS;
    }
}
