<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\UpdateGrindStepRequest;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotVehicle;
use App\Services\Wargaming\GrindBoard;
use App\Services\Wargaming\TechTree;
use Inertia\Inertia;
use Inertia\Response;

class GrindController extends Controller
{
    public function index(Request $request, GrindBoard $board): Response
    {
        $account = $request->user()->wotAccount;

        if (! $account) {
            return Inertia::render('Connect');
        }

        return Inertia::render('Grinding', [
            ...$board->for($account),
            // Only vehicles with a research line can be a target; premiums and
            // gifts sit outside the tree entirely.
            'options' => WotVehicle::query()
                ->whereNotIn('tank_id', $account->grindTargets()->pluck('tank_id'))
                ->where('is_premium', false)
                ->whereIn('tier', [8, 9, 10])
                ->get(['tank_id', 'name', 'tier', 'nation'])
                // Same tech-tree nation order as every other vehicle list here.
                ->sortBy(fn (WotVehicle $v): array => [$v->nationRank(), -$v->tier, $v->name])
                ->map(fn (WotVehicle $v): array => [
                    'tank_id' => $v->tank_id,
                    'label' => "{$v->name} (T{$v->tier}, {$v->nation})",
                ])->values(),
        ]);
    }

    /**
     * Adds a target and generates its path from the tech tree.
     */
    public function store(Request $request, TechTree $tree): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $validated = $request->validate([
            'tank_id' => ['required', 'integer', Rule::exists('wot_vehicles', 'tank_id')],
        ]);

        $target = WotGrindTarget::updateOrCreate(
            ['wot_account_id' => $account->id, 'tank_id' => $validated['tank_id']],
            ['sort_order' => (int) $account->grindTargets()->max('sort_order') + 1],
        );

        $target->steps()->delete();

        // Paths start from the last vehicle already played, so a new target
        // doesn't arrive listing tiers finished years ago.
        $owned = $account->vehicleSnapshots()->distinct()->pluck('tank_id')->all();

        foreach ($tree->pathTo($validated['tank_id'], $owned) as $step) {
            WotGrindStep::create([
                'wot_grind_target_id' => $target->id,
                'tank_id' => $step['tank_id'],
                'tier' => $step['tier'],
                'position' => $step['position'],
                'research_xp' => $step['research_xp'],
                'price_credit' => $step['price_credit'],
            ]);
        }

        return back(fallback: route('wot.grinding'));
    }

    public function updateStep(UpdateGrindStepRequest $request, WotGrindStep $step): RedirectResponse
    {
        $this->authorizeStep($request, $step);

        $step->update($request->validated());

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Ticks a module researched, or un-ticks it.
     *
     * The model does the arithmetic: banked XP drops by the module's cost,
     * because that is what happens in game when you research it. That is the
     * point of the feature — the alternative was doing the subtraction by hand.
     */
    public function updateModule(Request $request, WotGrindStep $step): RedirectResponse
    {
        $this->authorizeStep($request, $step);

        $validated = $request->validate([
            'module_id' => ['required', 'integer'],
            'researched' => ['required', 'boolean'],
        ]);

        $step->setModuleResearched($validated['module_id'], $validated['researched']);

        return back(fallback: route('wot.grinding'));
    }

    public function destroy(Request $request, WotGrindTarget $target): RedirectResponse
    {
        abort_unless($target->wot_account_id === $request->user()->wotAccount?->id, 404);

        $target->delete();

        return back(fallback: route('wot.grinding'));
    }
    public function complete(Request $request, WotGrindTarget $target): RedirectResponse
    {
        abort_unless($target->wot_account_id === $request->user()->wotAccount?->id, 404);

        $target->update(['completed_at' => $target->is_complete ? null : now()]);

        return back(fallback: route('wot.grinding'));
    }
    public function updateSettings(Request $request): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $validated = $request->validate([
            'credits_available' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'garage_slots_vacant' => ['required', 'integer', 'min:0', 'max:5000'],
        ]);

        WotGrindSetting::updateOrCreate(['wot_account_id' => $account->id], $validated);

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Steps are bound by primary key, so ownership is checked through the
     * target — otherwise any signed-in user could edit another's board.
     */
    private function authorizeStep(Request $request, WotGrindStep $step): void
    {
        abort_unless(
            $step->target?->wot_account_id === $request->user()->wotAccount?->id,
            404,
        );
    }
}
