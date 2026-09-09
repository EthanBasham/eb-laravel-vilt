<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotVehicle;

/**
 * Walks the research tree backwards from a target vehicle.
 *
 * The encyclopedia only points forwards — each vehicle lists what it unlocks —
 * so reaching "how do I get to the Concept No. 5" means inverting that into a
 * predecessor map first. Built once and reused, because doing it per target
 * would re-read all thousand vehicles each time.
 */
class TechTree
{
    /** @var Collection<int, WotVehicle>|null */
    private ?Collection $vehicles = null;

    /** @var array<int, array{vehicle: WotVehicle, cost: int}>|null */
    private ?array $predecessors = null;

    /**
     * The path to a target, ending at the target itself.
     *
     * Walks back until it reaches a vehicle the player owns, or the start of the
     * line. Each entry is a *step*: the vehicle you play, and what it unlocks
     * next.
     *
     * @param  list<int>  $ownedTankIds
     * @return list<array<string, mixed>>
     */
    public function pathTo(int $targetTankId, array $ownedTankIds = []): array
    {
        $target = $this->vehicles()->get($targetTankId);

        if (! $target) {
            return [];
        }

        $owned = array_flip($ownedTankIds);
        $chain = [];
        $current = $target;

        // Guarded rather than while(true): a data error that made the tree
        // cyclic would otherwise hang the request.
        for ($i = 0; $i < 12; $i++) {
            $link = $this->predecessors()[$current->tank_id] ?? null;

            if (! $link) {
                break;
            }

            array_unshift($chain, [
                'vehicle' => $link['vehicle'],
                'unlocks' => $current,
                'research_xp' => $link['cost'],
            ]);

            // Stop at the last vehicle already in the garage: everything below
            // it is done, and including it would pad the plan with tiers the
            // player finished years ago.
            if (isset($owned[$link['vehicle']->tank_id])) {
                break;
            }

            $current = $link['vehicle'];
        }

        // The target itself is a step too — it can carry module XP of its own,
        // which is what the spreadsheet's trailing "Tier X" column recorded.
        $chain[] = ['vehicle' => $target, 'unlocks' => null, 'research_xp' => null];

        return array_values(array_map(fn (array $step, int $i): array => [
            'position' => $i,
            'tank_id' => $step['vehicle']->tank_id,
            'tier' => $step['vehicle']->tier,
            'name' => $step['vehicle']->name,
            'unlocks_tank_id' => $step['unlocks']?->tank_id,
            'unlocks_name' => $step['unlocks']?->name,
            'research_xp' => $step['research_xp'],
            'price_credit' => $step['unlocks']?->price_credit,
        ], $chain, array_keys($chain)));
    }

    /** The vehicle that unlocks this one, if anything does. */
    public function predecessorOf(int $tankId): ?WotVehicle
    {
        return $this->predecessors()[$tankId]['vehicle'] ?? null;
    }

    /**
     * The vehicles below one on its line, lowest tier first, stopping at a tier.
     *
     * pathTo() truncates at the vehicle being played, so the tiers under it are
     * absent from a plan's steps even though the player demonstrably researched
     * through them to get there. The purchase board fills those back in, where
     * a missing cell would otherwise read as "nothing here" rather than "long
     * since bought".
     *
     * @return list<WotVehicle>
     */
    public function ancestorsOf(int $tankId, int $downToTier): array
    {
        $chain = [];
        $current = $this->vehicles()->get($tankId);

        // Same guard as pathTo: a cyclic tree must not hang the request.
        for ($i = 0; $i < 12 && $current; $i++) {
            $link = $this->predecessors()[$current->tank_id] ?? null;

            if (! $link || $link['vehicle']->tier < $downToTier) {
                break;
            }

            array_unshift($chain, $link['vehicle']);
            $current = $link['vehicle'];
        }

        return $chain;
    }

    /**
     * @return Collection<int, WotVehicle>
     */
    public function vehicles(): Collection
    {
        return $this->vehicles ??= WotVehicle::all()->keyBy('tank_id');
    }

    /**
     * tank_id => the vehicle that unlocks it, and for how much.
     *
     * A vehicle can be reachable from more than one predecessor; the cheapest
     * is kept, which is the route a player would actually take.
     *
     * @return array<int, array{vehicle: WotVehicle, cost: int}>
     */
    private function predecessors(): array
    {
        if ($this->predecessors !== null) {
            return $this->predecessors;
        }

        $map = [];

        foreach ($this->vehicles() as $vehicle) {
            foreach ($vehicle->next_tanks ?? [] as $unlockedId => $cost) {
                $unlockedId = (int) $unlockedId;
                $cost = (int) $cost;

                if (! isset($map[$unlockedId]) || $cost < $map[$unlockedId]['cost']) {
                    $map[$unlockedId] = ['vehicle' => $vehicle, 'cost' => $cost];
                }
            }
        }

        return $this->predecessors = $map;
    }
}
