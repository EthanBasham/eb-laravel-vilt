<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;

/**
 * Which vehicles on a line the player has, or has had.
 *
 * Every board over the tech tree needs this and none of them may answer it
 * differently: Tanks to Purchase decides what is still owed by it, XP Remaining
 * decides which unlocks are settled. Two derivations that drifted apart would
 * put the same tank on one tab as bought and on another as still to research.
 */
class LineOwnership
{
    /**
     * Ownership per vehicle along one line, lowest tier first.
     *
     * Anything below a vehicle you have *played* was researched through to
     * reach it, so it was owned too.
     *
     * It needs saying because "played" means in the garage with battles: sell a
     * tank after moving up the line and every trace of having owned it goes
     * with it, which left a researched-past tier IX reading as still to buy
     * under a tier X you have battles in.
     *
     * The trigger is deliberately play history and nothing else. Inferring from
     * is_purchased instead would make the rule contagious — ticking a tier IX
     * as bought would silently mark the VIII beneath it bought and researched
     * as well, which is a statement about the VIII that you did not make. Tanks
     * get sold, and saying so has to stay possible after the initial state is
     * worked out.
     *
     * An explicit purchase record wins over the inference either way.
     *
     * @param  Collection<int, WotVehicle>  $vehicles  one line's vehicles
     * @param  Collection<int, WotTankPurchase>  $purchases  keyed by tank id
     * @param  Collection<int, int>  $played  tank ids, flipped to keys
     * @return array<int, array{is_purchased: bool, is_unlocked: bool}>
     */
    public function along(Collection $vehicles, Collection $purchases, Collection $played): array
    {
        $state = [];
        $owned = false;

        // Highest tier first: the inference travels downwards, from the vehicle
        // you have battles in to everything you researched through to reach it.
        foreach ($vehicles->sortByDesc('tier') as $vehicle) {
            $purchase = $purchases->get($vehicle->tank_id);
            $purchased = $purchase?->is_purchased ?? $played->has($vehicle->tank_id);

            /*
             * Suppressed by a stated ownership, not by a row.
             *
             * The row also carries a blueprint-discounted research cost and a
             * fragment count, and typing either says nothing about whether you
             * own the tank — so is_purchased is null until something does.
             */
            if ($owned && $purchase?->is_purchased === null) {
                $purchased = true;
            }

            $state[$vehicle->tank_id] = [
                'is_purchased' => $purchased,
                // Buying implies researching, whatever the stored flag says.
                'is_unlocked' => $purchased || ($purchase?->is_unlocked ?? false),
            ];

            $owned = $owned || $played->has($vehicle->tank_id);
        }

        return $state;
    }
}
