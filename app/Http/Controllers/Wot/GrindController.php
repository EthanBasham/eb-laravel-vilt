<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\BoardFiltersRequest;
use App\Http\Requests\Wot\UpdateGrindStepRequest;
use App\Http\Requests\Wot\UpdateModulePlanRequest;
use App\Http\Requests\Wot\UpdateModuleResearchRequest;
use App\Http\Requests\Wot\UpdateResearchXpRequest;
use App\Http\Requests\Wot\UpdateTankPurchaseRequest;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotTankModule;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;
use App\Models\WotVehicleModule;
use App\Services\Wargaming\GrindBoard;
use App\Services\Wargaming\ModuleTree;
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

    /**
     * Marks a vehicle researched or bought, or overrides what it costs.
     *
     * Keyed on the tank rather than on a grind step: the tier XI above a target
     * belongs to no path, and a tank worth buying need not be one you are
     * currently grinding towards.
     */
    public function updatePurchase(UpdateTankPurchaseRequest $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        $purchase = WotTankPurchase::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ]);

        $unbought = $request->has('is_purchased') && ! $request->boolean('is_purchased');

        $purchase->fill($request->validated());

        // Buying a tank researches it, whatever order the buttons were pressed
        // in — the reverse is not true.
        if ($purchase->is_purchased) {
            $purchase->is_unlocked = true;
        }

        /*
         * And un-buying hands back the researched state rather than dropping two
         * steps at once: you cannot have owned a vehicle without researching it
         * first.
         *
         * This is what a vehicle that reads as bought only because it sits in
         * the garage depends on. PurchaseBoard resolves that cell's is_unlocked
         * from `$purchased` rather than from a stored flag, so there is nothing
         * behind it to fall back on — leaving the flag alone writes false and
         * the cell lands on unresearched.
         *
         * An explicit is_unlocked in the same request still wins; this only
         * fills in a value the caller did not give.
         */
        if ($unbought && ! $request->has('is_unlocked')) {
            $purchase->is_unlocked = true;
        }

        $purchase->save();

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Puts a module on the Free XP plan, or takes it off.
     *
     * Keyed on the tank rather than on a grind step, like updatePurchase(): the
     * Free XP board is the whole tech tree, and a module worth spending on need
     * not sit on a line you are currently tracking.
     *
     * firstOrNew, then save inside the model — a vehicle you have never touched
     * has no plan row, and the first tick is what creates one.
     */
    public function updateModulePlan(UpdateModulePlanRequest $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        $plan = WotTankModule::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ]);

        $plan->setModulePlanned($request->integer('module_id'), $request->boolean('planned'));

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Plans everything needed to unlock a vehicle's top gun.
     *
     * The chain is worked out here rather than sent by the client. The board
     * already ships it so the button can show its cost, but trusting that back
     * would let any list of module ids arrive under this name — and the rule
     * for which gun is "top" has to have exactly one home.
     */
    public function planTopGun(Request $request, int $tankId, ModuleTree $tree): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        // Stock modules included: they are the roots of the graph, and without
        // them every chain breaks at its first link.
        $chain = $tree->topGunChain(WotVehicleModule::where('tank_id', $tankId)->get());

        abort_unless($chain, 404);

        WotTankModule::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ])->planModules($chain['module_ids']);

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Marks a module researched on a vehicle, or un-marks it.
     *
     * The XP Remaining counterpart to updateModulePlan(), and keyed the same
     * way — on the tank, not on a grind step, because the board is the whole
     * tree. The model drops the module from the Free XP plan when it is
     * researched; nothing here needs to know that.
     */
    public function updateModuleResearch(UpdateModuleResearchRequest $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        WotTankModule::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ])->setModuleResearched($request->integer('module_id'), $request->boolean('researched'));

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Records what a vehicle actually costs to unlock after blueprints.
     *
     * On the vehicle being unlocked, matching where price_credit lives, so two
     * lines converging on one tank share the figure. Null clears the override
     * and hands the cell back to the encyclopedia's full price.
     */
    public function updateResearchXp(UpdateResearchXpRequest $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        WotTankPurchase::updateOrCreate(
            ['wot_account_id' => $account->id, 'tank_id' => $tankId],
            ['research_xp' => $request->input('research_xp')],
        );

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
     * Remembers where a board's filter row was left.
     *
     * Merged into whatever is stored rather than replacing it, so a client that
     * sends one changed filter does not silently reset the other three.
     *
     * Deliberately a separate endpoint from updateSettings(): that one takes
     * two required figures typed into a form and pressed Save, this one fires
     * on its own as you click filters, and folding them together would mean
     * every filter click had to resend the planning figures to survive their
     * `required` rules.
     *
     * 204 rather than the `back()` every other action here returns, because the
     * caller is a standalone `useHttp` request rather than an Inertia visit:
     * the board on screen already shows the filtered state, so redirecting
     * would rebuild the whole thing to produce props nobody reads.
     */
    public function updateFilters(BoardFiltersRequest $request): HttpResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $settings = WotGrindSetting::firstOrNew(['wot_account_id' => $account->id]);

        // 'board' says which column, so it is routing rather than filter state
        // and does not belong in the stored payload.
        $column = $request->string('board').'_filters';

        $settings->{$column} = [
            ...(array) $settings->{$column},
            ...collect($request->validated())->except('board')->all(),
        ];

        $settings->save();

        return response()->noContent();
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
