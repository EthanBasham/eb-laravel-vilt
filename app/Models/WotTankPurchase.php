<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everything the signed-in player has said about one vehicle: whether it is
 * researched and bought, what they will actually pay for it, how far its
 * blueprint has come and where the rest of it is meant to come from, and
 * whether they are grinding it right now.
 *
 * @property-read int $planned_fragments
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
            'blueprint_plan' => 'array',
            'price_credit' => 'integer',
            'is_playing' => 'boolean',
            'banked_xp' => 'integer',
        ];
    }

    /**
     * Fragments the plan would add, whichever nation pays for them.
     *
     * The entries are nations rather than kinds, so they sum: the blueprint
     * gains the same fragment whoever's blueprints bought it. What they cost
     * does not sum — a peer nation is charged six to one — and lives in
     * BlueprintCost.
     */
    protected function plannedFragments(): Attribute
    {
        return Attribute::get(fn (): int => (int) array_sum($this->blueprint_plan ?? []));
    }

    /**
     * Records how many of a vehicle's fragments one nation is meant to pay for.
     *
     * Deliberately not fillable: the plan is a map, and mass-assigning it whole
     * would let one nation's counter arrive carrying a rewrite of every other.
     * Each nation is written on its own, at its own URL — which is also why a
     * zero removes the entry rather than storing one. An absent nation and a
     * nation planned for zero fragments are the same state, and keeping both
     * spellings would mean two ways to say it.
     */
    public function setPlannedFragments(string $nation, int $fragments): void
    {
        $plan = $this->blueprint_plan ?? [];

        if ($fragments > 0) {
            $plan[$nation] = $fragments;
        } else {
            unset($plan[$nation]);
        }

        // ksort so two identical plans are one string in the database, whatever
        // order their nations were typed in.
        ksort($plan);

        $this->blueprint_plan = $plan;
        $this->save();
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
