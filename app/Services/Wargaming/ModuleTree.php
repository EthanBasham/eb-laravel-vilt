<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotVehicleModule;

/**
 * The research graph inside one vehicle.
 *
 * Modules are published forward — each says what it unlocks — so "what must I
 * research to get this" is the inversion, the same shape of problem TechTree
 * solves for vehicles. Dependencies cross slots: the Tiger II's Serienturm
 * turret is unlocked by its 10.5 cm gun, not by the stock turret, so nothing
 * here may assume a chain stays within one module type.
 */
class ModuleTree
{
    /**
     * The chain that unlocks a vehicle's top gun, top gun included.
     *
     * "Top gun" is the upgrade gun that no other gun leads on from, and where
     * more than one qualifies, the one whose chain costs most. Only 6 of the
     * 331 vehicles with a gun upgrade branch that way at all — the StuG III B's
     * derp against its Pak, the KV-4's 107 mm against its 122 mm — and in every
     * one the dearer chain is the gun a player means. The name travels with the
     * answer so the choice is never silent.
     *
     * Stock modules are excluded: they are fitted from the start, cost nothing,
     * and cannot be planned.
     *
     * @param  Collection<int, WotVehicleModule>  $modules  every module on one vehicle
     * @return array{module_id: int, name: string, module_ids: list<int>, xp: int}|null
     */
    public function topGunChain(Collection $modules): ?array
    {
        $byId = $modules->keyBy('module_id');
        $gunIds = $modules->where('type', 'vehicleGun')->pluck('module_id')->flip();

        $terminals = $modules
            ->where('type', 'vehicleGun')
            ->where('is_default', false)
            /*
             * A gun with another gun anywhere downstream is a step, not the
             * top — and "downstream" has to mean the whole graph rather than
             * one hop, because a gun can lead to the next one through a module
             * of a different kind. The KV-4 is exactly that: its 107 mm ZiS-24
             * unlocks the KV-4-5 turret, which unlocks the 122 mm D-25T, so a
             * one-hop test called the 107 mm a top gun.
             */
            ->reject(fn (WotVehicleModule $g): bool => $this->reaches($g->module_id, $gunIds, $byId));

        if ($terminals->isEmpty()) {
            return null;
        }

        return $terminals
            ->map(function (WotVehicleModule $gun) use ($byId): array {
                $chain = $this->chainTo($gun->module_id, $byId);

                return [
                    'module_id' => $gun->module_id,
                    'name' => $gun->name,
                    'module_ids' => $chain->pluck('module_id')->all(),
                    'xp' => (int) $chain->sum('price_xp'),
                ];
            })
            // Dearest chain first, then the dearer gun, then by name so a true
            // tie is at least stable between requests.
            ->sortByDesc(fn (array $c): array => [$c['xp'], $byId->get($c['module_id'])->price_xp, $c['name']])
            ->first();
    }

    /**
     * Whether any of the given modules sits downstream of this one.
     *
     * @param  Collection<int, int>  $targets  module ids, flipped to keys
     * @param  Collection<int, WotVehicleModule>  $byId
     */
    private function reaches(int $from, Collection $targets, Collection $byId): bool
    {
        $seen = [$from => true];
        $queue = $byId->get($from)?->next_modules ?? [];

        // Same guarded breadth-first walk as chainTo, in the other direction.
        while ($queue) {
            $id = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            if ($targets->has($id)) {
                return true;
            }

            foreach ($byId->get($id)?->next_modules ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }

    /**
     * Every upgrade module needed to reach one, itself included.
     *
     * @param  Collection<int, WotVehicleModule>  $byId
     * @return Collection<int, WotVehicleModule>
     */
    public function chainTo(int $moduleId, Collection $byId): Collection
    {
        $predecessors = $this->predecessors($byId);

        $seen = [];
        $queue = [$moduleId];

        // Breadth-first with a seen set rather than recursion: a module can be
        // unlocked by more than one, and a data error that made the graph
        // cyclic must not hang the request.
        while ($queue) {
            $id = array_shift($queue);

            if (isset($seen[$id]) || ! $byId->has($id)) {
                continue;
            }

            $seen[$id] = true;

            foreach ($predecessors[$id] ?? [] as $previous) {
                $queue[] = $previous;
            }
        }

        /*
         * filter, not only(): an Eloquent collection's only() selects by the
         * models' *primary key*, not by the collection's keys, so on a
         * collection keyed by module_id it silently matched nothing — or, in a
         * fixture whose row ids happened to equal its module ids, matched the
         * right rows for the wrong reason.
         */
        return $byId
            ->filter(fn (WotVehicleModule $m): bool => isset($seen[$m->module_id]))
            ->reject(fn (WotVehicleModule $m): bool => $m->is_default)
            // Cheapest first, which is the order they are researched in.
            ->sortBy('price_xp')
            ->values();
    }

    /**
     * next_modules, inverted.
     *
     * @param  Collection<int, WotVehicleModule>  $byId
     * @return array<int, list<int>>
     */
    private function predecessors(Collection $byId): array
    {
        $map = [];

        foreach ($byId as $module) {
            foreach ($module->next_modules ?? [] as $next) {
                $map[$next][] = $module->module_id;
            }
        }

        return $map;
    }
}
