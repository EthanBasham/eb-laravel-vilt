<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A researchable module on a vehicle. Rows are owned by the encyclopedia and
 * replaced by `wot:sync-vehicles`.
 */
#[Fillable(['module_id', 'tank_id', 'name', 'type', 'price_xp', 'price_credit', 'is_default', 'next_modules'])]
class WotVehicleModule extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module_id' => 'integer',
            'tank_id' => 'integer',
            'price_xp' => 'integer',
            'price_credit' => 'integer',
            'is_default' => 'boolean',
            'next_modules' => 'array',
        ];
    }

    /** A short label for the module's slot, for a dense list. */
    public function slot(): string
    {
        return match ($this->type) {
            'vehicleGun' => 'Gun',
            'vehicleTurret' => 'Turret',
            'vehicleEngine' => 'Engine',
            'vehicleChassis' => 'Chassis',
            'vehicleRadio' => 'Radio',
            default => $this->type,
        };
    }

    // Scopes

    /** Stock modules come fitted; only upgrades cost XP. */
    public function scopeOnlyUpgrades(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }

    // Relationships

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(WotVehicle::class, 'tank_id', 'tank_id');
    }
}
