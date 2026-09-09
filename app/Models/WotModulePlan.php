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

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
