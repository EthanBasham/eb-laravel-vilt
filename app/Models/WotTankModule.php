<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * What the player has done, and intends to do, about one vehicle's modules.
 *
 * Researched is a tri-state: said yes, said no, or said nothing and inherit the
 * default, which is that a vehicle whose successor you have unlocked had its
 * modules taken on the way. Two lists rather than one, so "no" is expressible
 * against a default of yes — the same arrangement the purchase board uses,
 * where an explicit row beats the inference drawn from play history.
 *
 * Planned is a plain set alongside it. Researching a module it was planned for
 * takes it out of the plan: there is nothing left to spend Free XP on.
 */
#[Fillable(['wot_account_id', 'tank_id', 'planned_module_ids', 'researched_module_ids', 'unresearched_module_ids'])]
class WotTankModule extends Model
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
            'planned_module_ids' => 'array',
            'researched_module_ids' => 'array',
            'unresearched_module_ids' => 'array',
        ];
    }

    /**
     * Adds a module to the Free XP plan, or takes it off.
     */
    public function setModulePlanned(int $moduleId, bool $planned, bool $researchedByDefault = false): void
    {
        // Nothing left to spend Free XP on once it is researched — including
        // where that is only the board's assumption, since that is what the
        // dropdown was showing when the click happened.
        if ($planned && self::isResearched($this, $moduleId, $researchedByDefault)) {
            return;
        }

        $this->toggle('planned_module_ids', $moduleId, $planned);
    }

    /**
     * Marks a module researched, or un-marks it.
     *
     * Researching one drops it from the Free XP plan in the same write. The
     * plan is a list of things still to buy, and leaving a researched module on
     * it would keep charging for something already paid for — on a board whose
     * whole job is to total what is outstanding.
     */
    public function setModuleResearched(int $moduleId, bool $researched): void
    {
        // Recorded on whichever list agrees, and struck from the other, so the
        // two can never both claim it.
        $this->toggle('researched_module_ids', $moduleId, $researched);
        $this->toggle('unresearched_module_ids', $moduleId, ! $researched);

        if ($researched) {
            $this->toggle('planned_module_ids', $moduleId, false);
        }
    }

    /**
     * Whether a module counts as researched, given the board's default.
     *
     * Static because the common case is having no row at all — a vehicle nobody
     * has ticked anything on — and making the caller conjure an empty model to
     * ask about the default would be the wrong way round.
     */
    public static function isResearched(?self $row, int $moduleId, bool $default): bool
    {
        if (in_array($moduleId, $row?->researched_module_ids ?? [], true)) {
            return true;
        }

        if (in_array($moduleId, $row?->unresearched_module_ids ?? [], true)) {
            return false;
        }

        return $default;
    }

    /**
     * Adds several modules to the plan at once, leaving the rest alone.
     *
     * One save rather than one per module: the top-gun button plans a whole
     * chain, and a request per link would be several round trips to express a
     * single decision.
     *
     * Additive only. The button says "plan these"; taking one back off is what
     * the checkboxes are for, and having it also clear unrelated modules would
     * make it a much larger claim than it looks.
     *
     * @param  list<int>  $moduleIds
     */
    public function planModules(array $moduleIds, bool $researchedByDefault = false): void
    {
        $valid = $this->upgrades($moduleIds)
            ->reject(fn (int $id): bool => self::isResearched($this, $id, $researchedByDefault));

        if ($valid->isEmpty()) {
            return;
        }

        $this->write('planned_module_ids', collect($this->planned_module_ids ?? [])->merge($valid));
    }

    /**
     * Adds one module to a set, or removes it.
     *
     * Validated against the encyclopedia rather than trusted: a module id that
     * belongs to another vehicle, or a stock one that costs nothing to fit,
     * would sit in the set contributing nothing and never appear in a dropdown
     * to be taken out again.
     */
    private function toggle(string $field, int $moduleId, bool $on): void
    {
        if ($this->upgrades([$moduleId])->isEmpty()) {
            return;
        }

        $ids = collect($this->{$field} ?? []);

        if ($on === $ids->contains($moduleId)) {
            return;
        }

        $this->write($field, $on ? $ids->push($moduleId) : $ids->reject(
            fn (int $id): bool => $id === $moduleId,
        ));
    }

    /**
     * @param  Collection<int, int>  $ids
     */
    private function write(string $field, Collection $ids): void
    {
        $next = $ids->unique()->sort()->values()->all();

        if ($next === ($this->{$field} ?? [])) {
            return;
        }

        $this->{$field} = $next;
        $this->save();
    }

    /**
     * The given ids that are genuinely upgrade modules on this vehicle.
     *
     * @param  list<int>  $moduleIds
     * @return Collection<int, int>
     */
    private function upgrades(array $moduleIds): Collection
    {
        return WotVehicleModule::where('tank_id', $this->tank_id)
            ->whereIn('module_id', $moduleIds)
            ->onlyUpgrades()
            ->pluck('module_id');
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
