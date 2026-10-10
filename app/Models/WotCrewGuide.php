<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\WotCrewGuideFactory;

/**
 * A named answer to "what should this crew train": for each role, the perks to
 * take and the order to take them in.
 *
 * Several can be kept side by side — a heavy that brawls and a light that
 * scouts want different things from the same five seats.
 */
#[Fillable(['wot_account_id', 'name'])]
class WotCrewGuide extends Model
{
    /** @use HasFactory<WotCrewGuideFactory> */
    use HasFactory;

    /**
     * Writes what was sent for one role, creating its row on first use.
     *
     * A role is saved a field at a time — a perk dropped, or a note left — so
     * only what is given is written. The first write for a role may be either,
     * which is why a note saved before any perk still gets an empty list.
     *
     * @param  array{included?: list<string>, notes?: string|null}  $values
     */
    public function saveRole(string $role, array $values): WotCrewGuideRole
    {
        $row = $this->roles()->firstOrNew(['role' => $role], ['included' => []]);

        $row->fill($values)->save();

        return $row;
    }

    // Scopes

    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }

    /** @return HasMany<WotCrewGuideRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(WotCrewGuideRole::class);
    }
}
