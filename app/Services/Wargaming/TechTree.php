<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotVehicle;

/**
 * Walks the research tree backwards.
 *
 * The encyclopedia only points forwards — each vehicle lists what it unlocks —
 * so asking what stands below a vehicle means inverting that into a predecessor
 * map first. Built once and reused, because doing it per question would re-read
 * all thousand vehicles each time.
 */
class TechTree
{
    /** @var Collection<int, WotVehicle>|null */
    private ?Collection $vehicles = null;

    /** @var array<int, array{vehicle: WotVehicle, cost: int}>|null */
    private ?array $predecessors = null;

    /** The vehicle that unlocks this one, if anything does. */
    public function predecessorOf(int $tankId): ?WotVehicle
    {
        return $this->predecessors()[$tankId]['vehicle'] ?? null;
    }

    /**
     * The vehicles below one on its line, lowest tier first, stopping at a tier.
     *
     * Every board over the tree is one row per line, and a line is a branch top
     * with its whole lineage behind it — so this is what TechTreeLines calls to
     * find that lineage.
     *
     * @return list<WotVehicle>
     */
    public function ancestorsOf(int $tankId, int $downToTier): array
    {
        $chain = [];
        $current = $this->vehicles()->get($tankId);

        // Guarded rather than while(true): a data error that made the tree
        // cyclic would otherwise hang the request.
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
