<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\WotGrindTargetFactory;

/**
 * A vehicle being worked towards, and the research path to it.
 *
 * @property-read bool $is_complete
 */
#[Fillable(['wot_account_id', 'tank_id', 'sort_order', 'completed_at', 'notes'])]
class WotGrindTarget extends Model
{
    /** @use HasFactory<WotGrindTargetFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tank_id' => 'integer',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    protected function isComplete(): Attribute
    {
        return Attribute::get(fn (): bool => $this->completed_at !== null);
    }

    /**
     * Total XP still required along the whole path: modules at every step, plus
     * each unlock, minus anything already banked.
     */
    public function xpRemaining(): int
    {
        return $this->steps->sum(fn (WotGrindStep $step): int => $step->xpRemaining());
    }

    /** Credits needed to buy every vehicle along the path. */
    public function creditsRequired(): int
    {
        return (int) $this->steps->sum('price_credit');
    }

    // Scopes

    public function scopeOnlyActive(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderByRaw('completed_at is not null')->orderBy('sort_order')->orderBy('id');
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

    /** @return HasMany<WotGrindStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WotGrindStep::class)->orderBy('position');
    }
}
