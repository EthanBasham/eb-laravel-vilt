<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Services\Wargaming\WargamingClient;
use App\Services\Wargaming\WargamingException;

/**
 * Answers "is the Wargaming API actually reachable with the configured credentials" without needing a linked account.
 *
 * A manual diagnostic, and deliberately the only command in this namespace that
 * is neither scheduled nor covered by a test. It issues a real, unmocked
 * request, which is the entire point: no test on a developer's machine can say
 * whether a production box's IP is registered at Wargaming, and faking the call
 * would leave this asserting nothing but its own output. WargamingClient itself
 * is covered properly, by WargamingClientTest with Http::fake().
 *
 *
 * Run it by hand after changing a key, changing realm, or moving to a new machine. See README.md, which uses it as the first step of setup.
 */
class PingApi extends Command
{
    protected $signature = 'wot:ping {nickname? : A player nickname to look up as a live check}';

    protected $description = 'Check that the configured Wargaming application ID works';

    public function handle(WargamingClient $client): int
    {
        $realm = config('wargaming.realm');
        $this->line("Realm: {$realm} (".config("wargaming.hosts.{$realm}").')');

        try {
            $accounts = $client->searchAccounts($this->argument('nickname') ?? 'AirsoftPro13', 3);
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
