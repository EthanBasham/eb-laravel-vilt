<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * What the player has done, and intends to do, about one vehicle's modules.
 *
 * Two independent sets: researched, which the XP Remaining board counts down,
 * and planned, which the Free XP board adds up. A module can be in neither, and
 * researching one it was planned for takes it out of the plan — there is
 * nothing left to spend Free XP on.
 */
#[Fillable(['wot_account_id', 'tank_id', 'planned_module_ids', 'researched_module_ids'])]
class WotTankModule extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tank_id' => 'integer',
            'planned_module_ids' => 'array',
            'researched_module_ids' => 'array',
        ];
    }

    /**
     * Adds a module to the Free XP plan, or takes it off.
     */
    public function setModulePlanned(int $moduleId, bool $planned): void
    {
        // Nothing left to spend Free XP on once it is researched.
        if ($planned && in_array($moduleId, $this->researched_module_ids ?? [], true)) {
            return;
        }

        $this->toggle('planned_module_ids', $moduleId, $planned);
    }

    /**
     * Marks a module researched, or un-marks it.
     *
     * Researching one drops it from the Free XP plan in the same write. The
     * plan is a list of things still to buy, and leaving a researched module on
     * it would keep charging for something already paid for — on a board whose
     * whole job is to total what is outstanding.
     */
    public function setModuleResearched(int $moduleId, bool $researched): void
    {
        $this->toggle('researched_module_ids', $moduleId, $researched);

        if ($researched) {
            $this->toggle('planned_module_ids', $moduleId, false);
        }
    }

    /**
     * Adds several modules to the plan at once, leaving the rest alone.
     *
     * One save rather than one per module: the top-gun button plans a whole
     * chain, and a request per link would be several round trips to express a
     * single decision.
     *
     * Additive only. The button says "plan these"; taking one back off is what
     * the checkboxes are for, and having it also clear unrelated modules would
     * make it a much larger claim than it looks.
     *
     * @param  list<int>  $moduleIds
     */
    public function planModules(array $moduleIds): void
    {
        $valid = $this->upgrades($moduleIds)
            ->diff($this->researched_module_ids ?? []);

        if ($valid->isEmpty()) {
            return;
        }

        $this->write('planned_module_ids', collect($this->planned_module_ids ?? [])->merge($valid));
    }

    /**
     * Adds one module to a set, or removes it.
     *
     * Validated against the encyclopedia rather than trusted: a module id that
     * belongs to another vehicle, or a stock one that costs nothing to fit,
     * would sit in the set contributing nothing and never appear in a dropdown
     * to be taken out again.
     */
    private function toggle(string $field, int $moduleId, bool $on): void
    {
        if ($this->upgrades([$moduleId])->isEmpty()) {
            return;
        }

        $ids = collect($this->{$field} ?? []);

        if ($on === $ids->contains($moduleId)) {
            return;
        }

        $this->write($field, $on ? $ids->push($moduleId) : $ids->reject(
            fn (int $id): bool => $id === $moduleId,
        ));
    }

    /**
     * @param  Collection<int, int>  $ids
     */
    private function write(string $field, Collection $ids): void
    {
        $next = $ids->unique()->sort()->values()->all();

        if ($next === ($this->{$field} ?? [])) {
            return;
        }

        $this->{$field} = $next;
        $this->save();
    }

    /**
     * The given ids that are genuinely upgrade modules on this vehicle.
     *
     * @param  list<int>  $moduleIds
     * @return Collection<int, int>
     */
    private function upgrades(array $moduleIds): Collection
    {
        return WotVehicleModule::where('tank_id', $this->tank_id)
            ->whereIn('module_id', $moduleIds)
            ->onlyUpgrades()
            ->pluck('module_id');
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
