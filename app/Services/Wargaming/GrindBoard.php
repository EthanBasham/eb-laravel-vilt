<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotVehicle;

/**
 * Assembles the grinding board.
 *
 * Five views over one dataset, mirroring the spreadsheet this replaced: what is
 * being played now, the XP left on every path, planned Free XP, the credits to
 * buy it all, and blueprint fragments held.
 */
class GrindBoard
{
    /**
     * @return array<string, mixed>
     */
    public function for(WotAccount $account): array
    {
        $targets = WotGrindTarget::with(['steps', 'vehicle'])
            ->where('wot_account_id', $account->id)
            ->get()
            // Completed targets stay at the bottom — they are no longer part of
            // the working list — and everything above them is in nation order.
            ->sortBy(fn (WotGrindTarget $t): array => [
                $t->is_complete ? 1 : 0,
                WotVehicle::rankOf($t->vehicle?->nation),
                -($t->vehicle?->tier ?? 0),
                $t->vehicle?->name ?? '',
            ])
            ->values();

        $vehicles = WotVehicle::whereIn('tank_id', $targets->flatMap->steps->pluck('tank_id')->unique())
            ->get()
            ->keyBy('tank_id');

        $settings = WotGrindSetting::firstOrNew(['wot_account_id' => $account->id]);

        return [
            'active' => $this->active($targets, $vehicles),
            'targets' => $targets->map(fn (WotGrindTarget $t): array => $this->target($t, $vehicles))->values()->all(),
            'settings' => [
                'credits_available' => (int) $settings->credits_available,
                'garage_slots_vacant' => (int) $settings->garage_slots_vacant,
            ],
            'totals' => $this->totals($targets),
        ];
    }

    /**
     * The tanks currently being played — the spreadsheet's Active Grinding.
     *
     * A step is shown here regardless of which target it belongs to, because
     * what matters is "what am I earning XP in right now".
     *
     * @param  Collection<int, WotGrindTarget>  $targets
     * @param  Collection<int, WotVehicle>  $vehicles
     * @return list<array<string, mixed>>
     */
    private function active(Collection $targets, Collection $vehicles): array
    {
        return $targets->flatMap->steps
            ->filter(fn (WotGrindStep $s): bool => $s->is_active)
            // Tech-tree nation order, then tier and name within a nation —
            // the way the garage itself is scanned.
            ->sortBy(fn (WotGrindStep $s): array => [
                WotVehicle::rankOf($vehicles->get($s->tank_id)?->nation),
                -$s->tier,
                $vehicles->get($s->tank_id)?->name ?? '',
            ])
            ->map(function (WotGrindStep $step) use ($targets, $vehicles): array {
                $target = $targets->firstWhere('id', $step->wot_grind_target_id);

                return [
                    'id' => $step->id,
                    'tank_id' => $step->tank_id,
                    'name' => $vehicles->get($step->tank_id)?->name ?? "Tank {$step->tank_id}",
                    'tier' => $step->tier,
                    'nation' => $vehicles->get($step->tank_id)?->nation,
                    'target_name' => $target?->vehicle?->name,
                    'banked_xp' => $step->banked_xp,
                    'module_xp_remaining' => $step->module_xp_remaining,
                    'research_cost' => $step->researchCost(),
                    'xp_required' => $step->xpRequired(),
                    'xp_remaining' => $step->xpRemaining(),
                    'progress' => $step->progress,
                    'modules' => $step->moduleOptions()->all(),
                ];
            })->values()->all();
    }

    /**
     * @param  Collection<int, WotVehicle>  $vehicles
     * @return array<string, mixed>
     */
    private function target(WotGrindTarget $target, Collection $vehicles): array
    {
        return [
            'id' => $target->id,
            'tank_id' => $target->tank_id,
            'name' => $target->vehicle?->name ?? "Tank {$target->tank_id}",
            'tier' => $target->vehicle?->tier,
            'nation' => $target->vehicle?->nation,
            'type' => $target->vehicle?->type,
            'is_complete' => $target->is_complete,
            'notes' => $target->notes,
            'xp_required' => $target->steps->sum(fn (WotGrindStep $s): int => $s->xpRequired()),
            'xp_remaining' => $target->xpRemaining(),
            'free_xp_planned' => $target->freeXpPlanned(),
            'credits_required' => $target->creditsRequired(),
            'blueprint_fragments' => (int) $target->steps->sum('blueprint_fragments'),
            'steps' => $target->steps->map(fn (WotGrindStep $s): array => [
                'id' => $s->id,
                'tank_id' => $s->tank_id,
                'name' => $vehicles->get($s->tank_id)?->name ?? "Tank {$s->tank_id}",
                'tier' => $s->tier,
                'position' => $s->position,
                'research_xp' => $s->research_xp,
                'research_xp_remaining' => $s->research_xp_remaining,
                'research_cost' => $s->researchCost(),
                'module_xp_remaining' => $s->module_xp_remaining,
                'banked_xp' => $s->banked_xp,
                'free_xp_planned' => $s->free_xp_planned,
                'blueprint_fragments' => $s->blueprint_fragments,
                'price_credit' => $s->price_credit,
                'is_active' => $s->is_active,
                'xp_required' => $s->xpRequired(),
                'xp_remaining' => $s->xpRemaining(),
                'progress' => $s->progress,
                'modules' => $s->moduleOptions()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, WotGrindTarget>  $targets
     * @return array<string, mixed>
     */
    private function totals(Collection $targets): array
    {
        $open = $targets->where('is_complete', false);

        return [
            'targets' => $targets->count(),
            'open' => $open->count(),
            'xp_required' => (int) $open->sum(fn (WotGrindTarget $t): int => $t->steps->sum(fn (WotGrindStep $s): int => $s->xpRequired())),
            'xp_remaining' => (int) $open->sum(fn (WotGrindTarget $t): int => $t->xpRemaining()),
            'free_xp_planned' => (int) $open->sum(fn (WotGrindTarget $t): int => $t->freeXpPlanned()),
            'credits_required' => (int) $open->sum(fn (WotGrindTarget $t): int => $t->creditsRequired()),
            'blueprint_fragments' => (int) $targets->flatMap->steps->sum('blueprint_fragments'),
            'banked_xp' => (int) $targets->flatMap->steps->sum('banked_xp'),
        ];
    }
}
