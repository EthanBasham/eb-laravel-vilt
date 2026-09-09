<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One tier along a research path: the vehicle you play, what its modules still
 * cost, and what the next unlock costs.
 *
 * @property-read float $progress
 */
#[Fillable([
    'wot_grind_target_id', 'tank_id', 'tier', 'position', 'research_xp', 'research_xp_remaining',
    'module_xp_remaining', 'banked_xp', 'blueprint_fragments', 'price_credit', 'is_active',
    'researched_modules',
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
            'blueprint_fragments' => 'integer',
            'price_credit' => 'integer',
            'is_active' => 'boolean',
            'researched_modules' => 'array',
        ];
    }

    /**
     * The upgrade modules on this step's vehicle, each flagged researched.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function moduleOptions(): Collection
    {
        $done = array_flip($this->researched_modules ?? []);

        return WotVehicleModule::where('tank_id', $this->tank_id)
            ->onlyUpgrades()
            ->orderByDesc('price_xp')
            ->get()
            ->map(fn (WotVehicleModule $m): array => [
                'module_id' => $m->module_id,
                'name' => $m->name,
                'slot' => $m->slot(),
                'price_xp' => $m->price_xp,
                'is_researched' => isset($done[$m->module_id]),
            ]);
    }

    /**
     * Marks a module researched (or not) and keeps the two derived numbers in
     * step with it.
     *
     * Researching a module spends banked XP in game, so the banked figure drops
     * by exactly the module's cost — the whole point of tracking modules here
     * rather than maintaining an "XP to Max" total by hand. Un-ticking reverses
     * it, so a misclick costs nothing.
     */
    public function setModuleResearched(int $moduleId, bool $researched): void
    {
        $module = WotVehicleModule::where('tank_id', $this->tank_id)
            ->where('module_id', $moduleId)
            ->onlyUpgrades()
            ->first();

        if (! $module) {
            return;
        }

        $done = collect($this->researched_modules ?? []);

        if ($researched === $done->contains($moduleId)) {
            return;
        }

        $done = $researched
            ? $done->push($moduleId)
            : $done->reject(fn (int $id): bool => $id === $moduleId);

        $this->researched_modules = $done->unique()->values()->all();

        // Floored at zero: banked XP can legitimately be lower than a module's
        // cost if it was researched with Free XP, and a negative balance would
        // be nonsense on the page.
        $this->banked_xp = $researched
            ? max(0, (int) $this->banked_xp - $module->price_xp)
            : (int) $this->banked_xp + $module->price_xp;

        $this->module_xp_remaining = $this->outstandingModuleXp();

        $this->save();
    }

    /**
     * Sum of the upgrade modules still to research.
     *
     * Falls back to the stored figure when the vehicle has no module rows —
     * a vehicle added before the encyclopedia sync knew about modules would
     * otherwise silently report as fully upgraded.
     */
    public function outstandingModuleXp(): int
    {
        $modules = WotVehicleModule::where('tank_id', $this->tank_id)->onlyUpgrades()->get();

        if ($modules->isEmpty()) {
            return (int) $this->module_xp_remaining;
        }

        $done = array_flip($this->researched_modules ?? []);

        return (int) $modules->reject(fn (WotVehicleModule $m): bool => isset($done[$m->module_id]))->sum('price_xp');
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
     * What is left to earn, after the XP already banked on this vehicle.
     *
     * Free XP no longer figures here. It used to, as a per-step number that
     * could be earmarked against anything; it is now a statement about which
     * modules will be bought with it, and a planned module still has to be
     * paid for — with Free XP instead of banked XP, but paid for. Subtracting
     * it twice was the old behaviour, not a feature lost.
     */
    public function xpRemaining(): int
    {
        return max(0, $this->xpRequired() - (int) $this->banked_xp);
    }

    protected function progress(): Attribute
    {
        return Attribute::get(function (): float {
            $required = $this->xpRequired();

            if ($required <= 0) {
                return 100.0;
            }

            return round(min(100, (int) $this->banked_xp / $required * 100), 1);
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
