<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everything the signed-in player has said about one vehicle: whether it is
 * researched and bought, what they will actually pay for it, and whether they
 * are grinding it right now.
 *
 * The table is named for the first of those because it was the first. Being on
 * the Active Grinding list is not a purchase, but it is a per-tank fact keyed
 * the same way and read by the same four boards, and a table of its own would
 * have bought nothing but a fifth lookup.
 *
 * A row exists only once something has been said about the tank; everything
 * else falls back to defaults derived from what the account has played. See
 * PurchaseBoard.
 */
#[Fillable(['wot_account_id', 'tank_id', 'is_unlocked', 'is_purchased', 'price_credit', 'research_xp', 'blueprint_fragments', 'is_playing', 'banked_xp'])]
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
            'blueprint_fragments' => 'integer',
            'price_credit' => 'integer',
            'is_playing' => 'boolean',
            'banked_xp' => 'integer',
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
