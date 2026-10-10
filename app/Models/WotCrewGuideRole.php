<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role's answer within a guide: the perks to train, in order, and a note.
 *
 * Only what is included is stored. The rest of what the role can train is the
 * excluded bucket, worked out against config('wargaming.crew_role_perks') when
 * the page is built.
 */
#[Fillable(['role', 'included', 'notes'])]
class WotCrewGuideRole extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'included' => 'array',
        ];
    }

    // Relationships

    public function guide(): BelongsTo
    {
        return $this->belongsTo(WotCrewGuide::class, 'wot_crew_guide_id');
    }
}
