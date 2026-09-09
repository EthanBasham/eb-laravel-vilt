<?php

namespace App\Services\Wargaming;

use App\Models\WotAccount;

/**
 * What one account has researched, per vehicle, across the whole tree.
 *
 * Every board reads this and none may derive it separately. It is also the one
 * place that unions ownership across lines: LineOwnership answers along a
 * single line, but owning a tank is a fact about the tank, so a vehicle
 * inferred owned on one line is owned on every line that shows it.
 */
class AccountProgress
{
    /** @var array<int, array<int, array{is_purchased: bool, is_unlocked: bool, modules_researched: bool}>> */
    private array $cache = [];

    public function __construct(
        private readonly TechTreeLines $lines,
        private readonly LineOwnership $ownership,
    ) {}

    /**
     * @return array<int, array{is_purchased: bool, is_unlocked: bool, modules_researched: bool}>
     */
    public function for(WotAccount $account): array
    {
        return $this->cache[$account->id] ??= $this->build($account);
    }

    /**
     * @return array<int, array{is_purchased: bool, is_unlocked: bool, modules_researched: bool}>
     */
    private function build(WotAccount $account): array
    {
        $lines = $this->lines->lines((int) config('wargaming.line_min_tier'));
        $purchases = $account->tankPurchases()->get()->keyBy('tank_id');
        $played = $account->vehicleSnapshots()->distinct()->pluck('tank_id')->flip();

        $state = [];

        foreach ($lines as $line) {
            foreach ($this->ownership->along($line['vehicles'], $purchases, $played) as $tankId => $owned) {
                // Unioned, not overwritten: a vehicle the back-fill settles on
                // one line is settled, even where another line shows it under
                // nothing you have played.
                $state[$tankId] = [
                    'is_purchased' => ($state[$tankId]['is_purchased'] ?? false) || $owned['is_purchased'],
                    'is_unlocked' => ($state[$tankId]['is_unlocked'] ?? false) || $owned['is_unlocked'],
                ];
            }
        }

        return $this->withModuleDefaults($state);
    }

    /**
     * Adds the assumption that finishing a vehicle means finishing its modules.
     *
     * Researching the next tank along is done from this one, so a player who
     * got there had every reason to take its modules on the way — and in
     * practice does, because the top gun is most of what makes the grind
     * bearable. The alternative default was that nothing is researched, which
     * on an account with 547 settled unlocks quoted tens of millions of XP
     * against tanks finished years ago.
     *
     * It is only ever a default. An explicit tick, either way, wins over it.
     *
     * @param  array<int, array{is_purchased: bool, is_unlocked: bool}>  $state
     * @return array<int, array{is_purchased: bool, is_unlocked: bool, modules_researched: bool}>
     */
    private function withModuleDefaults(array $state): array
    {
        $vehicles = $this->lines->vehicles();

        foreach ($state as $tankId => $owned) {
            /*
             * Any successor, not the next one on this line. A vehicle can lead
             * to two tier Xs, and unlocking either says the same thing about
             * the vehicle you unlocked it from.
             */
            $state[$tankId]['modules_researched'] = collect(array_keys((array) ($vehicles->get($tankId)?->next_tanks ?? [])))
                ->contains(fn ($nextId): bool => $state[(int) $nextId]['is_unlocked'] ?? false);
        }

        return $state;
    }
}
