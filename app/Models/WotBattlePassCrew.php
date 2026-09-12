<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named tanker from a Battle Pass season, and where they ended up.
 *
 * The only crew record here that is a person rather than a count.
 */
#[Fillable(['wot_account_id', 'name', 'nation', 'season', 'gender', 'status', 'tank_id', 'crew_role'])]
class WotBattlePassCrew extends Model
{
    /**
     * Singular on purpose: "crew" is already a collective, and pluralising it
     * to wot_battle_pass_crews would read as a table of crews rather than of
     * crew members.
     */
    protected $table = 'wot_battle_pass_crew';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'tank_id' => 'integer',
        ];
    }

    // Scopes

    /**
     * Newest season first, then by name.
     *
     * A season with no number sorts last rather than first, so a row entered
     * before its season is known does not head the list.
     */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderByRaw('season is null')->orderByDesc('season')->orderBy('name');
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(WotVehicle::class, 'tank_id', 'tank_id');
    }
}
