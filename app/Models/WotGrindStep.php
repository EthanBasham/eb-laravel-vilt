<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tier along a research path: the vehicle you play, what its modules still
 * cost, and what the next unlock costs.
 *
 * @property-read float $progress
 */
#[Fillable([
    'wot_grind_target_id', 'tank_id', 'tier', 'position', 'research_xp', 'research_xp_remaining',
    'module_xp_remaining', 'banked_xp', 'free_xp_planned', 'blueprint_fragments', 'price_credit', 'is_active',
])]
class WotGrindStep extends Model
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
            'tier' => 'integer',
            'position' => 'integer',
            'research_xp' => 'integer',
            'research_xp_remaining' => 'integer',
            'module_xp_remaining' => 'integer',
            'banked_xp' => 'integer',
            'free_xp_planned' => 'integer',
            'blueprint_fragments' => 'integer',
            'price_credit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * What the next unlock actually costs: the blueprint-discounted figure when
     * one has been entered, otherwise the API's full price.
     */
    public function researchCost(): int
    {
        return (int) ($this->research_xp_remaining ?? $this->research_xp ?? 0);
    }

    /** Everything this step still demands, before anything banked. */
    public function xpRequired(): int
    {
        return $this->researchCost() + (int) $this->module_xp_remaining;
    }

    /**
     * What is left to earn. Free XP counts as already covered — it's earmarked
     * for exactly this — and banked XP is subtracted, so a step never reads as
     * needing more than it does.
     */
    public function xpRemaining(): int
    {
        return max(0, $this->xpRequired() - (int) $this->banked_xp - (int) $this->free_xp_planned);
    }

    protected function progress(): Attribute
    {
        return Attribute::get(function (): float {
            $required = $this->xpRequired();

            if ($required <= 0) {
                return 100.0;
            }

            return round(min(100, ((int) $this->banked_xp + (int) $this->free_xp_planned) / $required * 100), 1);
        });
    }

    // Scopes

    public function scopeOnlyActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // Relationships

    public function target(): BelongsTo
    {
        return $this->belongsTo(WotGrindTarget::class, 'wot_grind_target_id');
    }
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(WotVehicle::class, 'tank_id', 'tank_id');
    }
}
