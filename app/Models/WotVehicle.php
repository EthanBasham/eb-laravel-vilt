<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\WotVehicleFactory;

/**
 * A vehicle from Wargaming's encyclopedia. Rows are wholly owned by the
 * upstream API and replaced by `wot:sync-vehicles`.
 */
#[Fillable(['tank_id', 'name', 'short_name', 'tier', 'nation', 'type', 'is_premium', 'image_url', 'next_tanks', 'price_credit'])]
class WotVehicle extends Model
{
    /** @use HasFactory<WotVehicleFactory> */
    use HasFactory;

    protected $primaryKey = 'tank_id';

    // Wargaming assigns tank_id; nothing here generates one.
    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tank_id' => 'integer',
            'tier' => 'integer',
            'is_premium' => 'boolean',
            'next_tanks' => 'array',
            'price_credit' => 'integer',
        ];
    }

    // Scopes

    public function scopeOnlyPremium(Builder $query): Builder
    {
        return $query->where('is_premium', true);
    }
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('tier')->orderBy('name');
    }
}
