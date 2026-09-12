<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat in a crew.
 *
 * Identified by its slot in the vehicle's own crew list rather than by role:
 * `member_id` repeats within a vehicle — an IS-7 carries two loaders — so the
 * role is not unique enough to key on. What that slot *is* comes from
 * wot_vehicles.crew, which is the encyclopedia's to describe.
 *
 * @property-read bool $is_zero_skill
 */
#[Fillable(['wot_tank_crew_id', 'slot', 'zero_skills', 'skill_level', 'is_max', 'banked_xp'])]
class WotCrewMember extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'zero_skills' => 'integer',
            'skill_level' => 'integer',
            'is_max' => 'boolean',
            'banked_xp' => 'integer',
        ];
    }

    /**
     * Whether any XP step has been zeroed out on this member.
     *
     * One is enough. The count says how much of a head start they have — and
     * is what the rule under the letter reports — but a member with either
     * one or two is a zero-skill crew member for the cell's colour.
     */
    protected function isZeroSkill(): Attribute
    {
        return Attribute::get(fn (): bool => $this->zero_skills > 0);
    }

    // Relationships

    public function crew(): BelongsTo
    {
        return $this->belongsTo(WotTankCrew::class, 'wot_tank_crew_id');
    }
}
