<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vehicle's totals at a point in time. Written only when that vehicle's
 * battle count changed, so the table stays small.
 */
#[Fillable(['wot_account_id', 'tank_id', 'captured_at', 'battles', 'statistics'])]
class WotVehicleSnapshot extends Model
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
            'captured_at' => 'datetime',
            'battles' => 'integer',
            'statistics' => 'array',
        ];
    }

    // Scopes

    public function scopeOnlyAtOrBefore(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('captured_at', '<=', $moment);
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
