<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\BoardFiltersRequest;
use App\Http\Requests\Wot\UpdateModulePlanRequest;
use App\Http\Requests\Wot\UpdateModuleResearchRequest;
use App\Http\Requests\Wot\UpdateResearchXpRequest;
use App\Http\Requests\Wot\UpdateTankPurchaseRequest;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotTankModule;
use App\Models\WotTankPurchase;
use App\Models\WotVehicle;
use App\Models\WotVehicleModule;
use App\Services\Wargaming\AccountProgress;
use App\Services\Wargaming\GrindBoard;
use App\Services\Wargaming\ModuleTree;
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

        return Inertia::render('Grinding', $board->for($account));
    }

    /**
     * Marks a vehicle researched or bought, or overrides what it costs.
     *
     * Keyed on the tank, like every write on this page: the board is the whole
     * tech tree, and a tank worth buying need not be one you are playing.
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
     * Keyed on the tank, like updatePurchase(): the Free XP board is the whole
     * tech tree, and a module worth spending on need not sit on a line you are
     * playing.
     *
     * firstOrNew, then save inside the model — a vehicle you have never touched
     * has no plan row, and the first tick is what creates one.
     */
    public function updateModulePlan(
        UpdateModulePlanRequest $request,
        int $tankId,
        AccountProgress $progress,
    ): RedirectResponse {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        $plan = WotTankModule::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ]);

        $plan->setModulePlanned(
            $request->integer('module_id'),
            $request->boolean('planned'),
            $progress->for($account)[$tankId]['modules_researched'] ?? false,
        );

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
    public function planTopGun(
        Request $request,
        int $tankId,
        ModuleTree $tree,
        AccountProgress $progress,
    ): RedirectResponse {
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
        ])->planModules(
            $chain['module_ids'],
            $progress->for($account)[$tankId]['modules_researched'] ?? false,
        );

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Marks a module researched on a vehicle, or un-marks it.
     *
     * The XP Remaining counterpart to updateModulePlan(), and keyed the same
     * way. The model drops the module from the Free XP plan when it is
     * researched; nothing here needs to know that.
     *
     * It does have to know about banked XP, which is the one thing the model
     * cannot see: researching a module spends it, so the balance drops by
     * exactly what the module cost. That was the point of ticking modules here
     * rather than keeping an "XP to max" total by hand.
     */
    public function updateModuleResearch(UpdateModuleResearchRequest $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless(WotVehicle::where('tank_id', $tankId)->exists(), 404);

        $tankModule = WotTankModule::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ]);

        $moduleId = $request->integer('module_id');
        $researched = $request->boolean('researched');

        /*
         * Read before the write, so a repeated tick is not charged twice.
         *
         * Against a default of false rather than the board's assumption: what
         * moves the balance is a module you have said something about. The two
         * only differ once a successor is unlocked — the grind is over by then,
         * and a tank that far along is not on the Active Grinding list.
         */
        $wasResearched = WotTankModule::isResearched($tankModule, $moduleId, false);

        $tankModule->setModuleResearched($moduleId, $researched);

        if ($researched !== $wasResearched) {
            $this->spendBankedXp($account, $tankId, $moduleId, $researched);
        }

        return back(fallback: route('wot.grinding'));
    }

    /**
     * Moves a tank's banked XP by the cost of a module just ticked or un-ticked.
     *
     * Only for a tank on the Active Grinding list. Banked XP is a fact about a
     * grind in progress, and this used to live on the step behind that list, so
     * a tank you are not playing has no balance for a tick to spend. Floored at
     * zero: a module bought with Free XP leaves less banked than it cost, and a
     * negative balance would be nonsense on the page.
     */
    private function spendBankedXp(WotAccount $account, int $tankId, int $moduleId, bool $researched): void
    {
        $purchase = WotTankPurchase::where('wot_account_id', $account->id)
            ->where('tank_id', $tankId)
            ->where('is_playing', true)
            ->first();

        $module = WotVehicleModule::where('tank_id', $tankId)
            ->where('module_id', $moduleId)
            ->onlyUpgrades()
            ->first();

        if (! $purchase || ! $module) {
            return;
        }

        $purchase->banked_xp = $researched
            ? max(0, (int) $purchase->banked_xp - (int) $module->price_xp)
            : (int) $purchase->banked_xp + (int) $module->price_xp;

        $purchase->save();
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

    /**
     * Remembers where a board's filter row was left.
     *
     * Merged into whatever is stored rather than replacing it, so a client that
     * sends one changed filter does not silently reset the other three.
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
}
