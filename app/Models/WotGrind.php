<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\WotGrindFactory;

/**
 * A declared grind: a vehicle being played towards a specific unlock.
 *
 * The declaration is required rather than inferred. Wargaming exposes no
 * per-vehicle unspent XP and no researched-module list, so `baseline_xp` — the
 * vehicle's lifetime XP when the grind started — is the only fixed point
 * progress can be measured from.
 *
 * @property-read bool $is_complete
 */
#[Fillable([
    'wot_account_id', 'tank_id', 'target_type', 'target_id', 'target_name', 'target_xp', 'baseline_xp', 'started_at', 'completed_at',
])]
class WotGrind extends Model
{
    /** @use HasFactory<WotGrindFactory> */
    use HasFactory;

    public const TARGET_TANK = 'tank';

    public const TARGET_MODULE = 'module';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tank_id' => 'integer',
            'target_id' => 'integer',
            'target_xp' => 'integer',
            'baseline_xp' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected function isComplete(): Attribute
    {
        return Attribute::get(fn (): bool => $this->completed_at !== null);
    }

    /**
     * XP earned towards this grind, given the vehicle's current lifetime total.
     *
     * Clamped at zero: Wargaming occasionally restates historical totals, and a
     * negative "earned" is meaningless to a reader.
     */
    public function earned(int $currentXp): int
    {
        return max(0, $currentXp - $this->baseline_xp);
    }

    public function remaining(int $currentXp): int
    {
        return max(0, $this->target_xp - $this->earned($currentXp));
    }

    /**
     * Marks the grind finished the first time the target is reached, so the
     * completion date reflects when it actually happened rather than when the
     * page was next opened.
     */
    public function completeIfReached(int $currentXp): void
    {
        if ($this->completed_at === null && $this->earned($currentXp) >= $this->target_xp) {
            $this->update(['completed_at' => now()]);
        }
    }

    // Scopes

    public function scopeOnlyActive(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        // Unfinished first, then most recently started.
        return $query->orderByRaw('completed_at is not null')->orderByDesc('started_at');
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
