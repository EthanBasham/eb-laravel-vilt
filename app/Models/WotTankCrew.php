<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The crew sitting in one vehicle, for one account.
 *
 * The row itself carries only what belongs to the set — whether it is balanced
 * — and the members carry the rest. Its existence is also a fact the board
 * reads: no row means no crew in that vehicle, which is the red state.
 *
 * The three derived attributes here are what colour and weight a cell, and all
 * three read the members relation, so a caller that wants them must load it.
 * They are accessors rather than helpers because the board reads them as data
 * and Inertia serialises them by name.
 *
 * @property-read bool $is_max
 * @property-read string $zero_state
 * @property-read int $banked_xp
 */
#[Fillable(['wot_account_id', 'tank_id', 'is_balanced'])]
class WotTankCrew extends Model
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
            'is_balanced' => 'boolean',
        ];
    }

    /**
     * Whether every member of the set is maxed.
     *
     * An empty set is not: a crew with no members recorded has nothing to be
     * finished about, and treating it as complete would paint the cell green
     * for a vehicle nobody has crewed.
     */
    protected function isMax(): Attribute
    {
        return Attribute::get(fn (): bool => $this->members->isNotEmpty()
            && $this->members->every(fn (WotCrewMember $member): bool => $member->is_max));
    }

    /**
     * How much of the set is zero-skill, as the board's four colours.
     *
     * 'none' belongs to a vehicle with no crew at all and so cannot arise here;
     * it is named in the same vocabulary anyway, because the board switches on
     * one value and the cell for a crewless tank has to answer the same
     * question.
     */
    protected function zeroState(): Attribute
    {
        return Attribute::get(function (): string {
            $zeroed = $this->members->filter(fn (WotCrewMember $member): bool => $member->is_zero_skill)->count();

            if ($zeroed === 0) {
                return 'plain';
            }

            return $zeroed === $this->members->count() ? 'all' : 'mixed';
        });
    }

    /** Banked XP across the set — the members hold it one by one. */
    protected function bankedXp(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->members->sum('banked_xp'));
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

    /**
     * The crew, in the vehicle's own slot order — which is the order the board
     * spells its letters in.
     */
    public function members(): HasMany
    {
        return $this->hasMany(WotCrewMember::class)->orderBy('slot');
    }
}
