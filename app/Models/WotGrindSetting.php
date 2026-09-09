<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Board-wide state that belongs to no single target: the two planning
 * figures, and where each board's filter row was left.
 */
#[Fillable(['wot_account_id', 'credits_available', 'garage_slots_vacant', 'purchase_filters', 'freexp_filters'])]
class WotGrindSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits_available' => 'integer',
            'garage_slots_vacant' => 'integer',
            'purchase_filters' => 'array',
            'freexp_filters' => 'array',
        ];
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
