<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The modules on one vehicle that the player intends to buy with Free XP.
 *
 * @property-read int $planned_xp
 */
#[Fillable(['wot_account_id', 'tank_id', 'module_ids'])]
class WotModulePlan extends Model
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
            'module_ids' => 'array',
        ];
    }

    /**
     * Adds a module to the plan, or takes it off.
     *
     * Validated against the encyclopedia rather than trusted: a module id that
     * belongs to another vehicle, or a stock one that costs nothing to fit,
     * would sit in the plan contributing nothing and never appear in the
     * dropdown to be removed again.
     */
    public function setModulePlanned(int $moduleId, bool $planned): void
    {
        $exists = WotVehicleModule::where('tank_id', $this->tank_id)
            ->where('module_id', $moduleId)
            ->onlyUpgrades()
            ->exists();

        if (! $exists) {
            return;
        }

        $ids = collect($this->module_ids ?? []);

        if ($planned === $ids->contains($moduleId)) {
            return;
        }

        $this->module_ids = ($planned ? $ids->push($moduleId) : $ids->reject(
            fn (int $id): bool => $id === $moduleId,
        ))->unique()->sort()->values()->all();

        $this->save();
    }

    /**
     * Adds several modules at once, leaving anything already planned alone.
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
        $valid = WotVehicleModule::where('tank_id', $this->tank_id)
            ->whereIn('module_id', $moduleIds)
            ->onlyUpgrades()
            ->pluck('module_id');

        if ($valid->isEmpty()) {
            return;
        }

        $ids = collect($this->module_ids ?? [])->merge($valid)->unique()->sort()->values()->all();

        if ($ids === ($this->module_ids ?? [])) {
            return;
        }

        $this->module_ids = $ids;
        $this->save();
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
