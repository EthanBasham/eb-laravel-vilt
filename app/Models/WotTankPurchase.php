<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether the signed-in player has researched and bought one vehicle, and what
 * they will actually pay for it.
 *
 * A row exists only once something has been said about the tank; everything
 * else falls back to defaults derived from what the account has played. See
 * PurchaseBoard.
 */
#[Fillable(['wot_account_id', 'tank_id', 'is_unlocked', 'is_purchased', 'price_credit', 'research_xp'])]
class WotTankPurchase extends Model
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
            'is_unlocked' => 'boolean',
            'is_purchased' => 'boolean',
            'research_xp' => 'integer',
            'price_credit' => 'integer',
        ];
    }

    // Relations

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(WotVehicle::class, 'tank_id', 'tank_id');
    }
}
