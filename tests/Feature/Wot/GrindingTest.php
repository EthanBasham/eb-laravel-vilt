<?php

use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotTankModule;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;
use App\Models\WotVehicleModule;
use App\Models\WotTankPurchase;

/** A three-tier line: T8 -> T9 -> T10. */
function techLine(): array
{
    $ten = WotVehicle::factory()->create(['tank_id' => 100, 'name' => 'Target X', 'short_name' => 'Tgt X', 'tier' => 10, 'type' => 'mediumTank', 'price_credit' => 6_100_000, 'next_tanks' => null]);
    $nine = WotVehicle::factory()->create(['tank_id' => 90, 'name' => 'Mid IX', 'tier' => 9, 'price_credit' => 3_400_000, 'next_tanks' => [100 => 225_000]]);
    $eight = WotVehicle::factory()->create(['tank_id' => 80, 'name' => 'Low VIII', 'tier' => 8, 'price_credit' => 2_400_000, 'next_tanks' => [90 => 149_400]]);

    return [$eight, $nine, $ten];
}

it('keeps the board behind auth', function () {
    $this->get(route('wot.grinding'))->assertRedirect(route('login'));
});

it('builds the path from the tech tree when a target is added', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->post(route('wot.grinding.store'), ['tank_id' => $ten->tank_id])->assertRedirect();

    $steps = WotGrindTarget::first()->steps;

    expect($steps)->toHaveCount(3)
        // Ordered low tier first, each step carrying what it unlocks.
        ->and($steps[0]->tier)->toBe(8)
        ->and($steps[0]->research_xp)->toBe(149_400)
        ->and($steps[0]->price_credit)->toBe(3_400_000)
        ->and($steps[1]->research_xp)->toBe(225_000)
        // The target itself is a step; it unlocks nothing further.
        ->and($steps[2]->tier)->toBe(10)
        ->and($steps[2]->research_xp)->toBeNull();
});

/**
 * The path should start where the player actually is, not at tier 1 — otherwise
 * a new target arrives listing tiers finished years ago.
 */
it('truncates the path at the last vehicle played', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    WotVehicleSnapshot::create([
        'wot_account_id' => $account->id, 'tank_id' => $nine->tank_id,
        'captured_at' => now(), 'battles' => 10, 'statistics' => ['battles' => 10],
    ]);

    $this->actingAs($user)->post(route('wot.grinding.store'), ['tank_id' => $ten->tank_id]);

    $steps = WotGrindTarget::first()->steps;

    expect($steps)->toHaveCount(2)
        ->and($steps[0]->tier)->toBe(9);
});

it('totals a path the way the spreadsheet did', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);

    // Mirrors the Concept No. 5 row: modules at two tiers plus two unlocks.
    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 80, 'tier' => 8, 'position' => 0,
        'research_xp' => 149_400, 'research_xp_remaining' => 109_062, 'module_xp_remaining' => 75_400, 'price_credit' => 3_400_000]);
    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 90, 'tier' => 9, 'position' => 1,
        'research_xp' => 225_000, 'research_xp_remaining' => 225_000, 'module_xp_remaining' => 124_400, 'price_credit' => 6_100_000]);
    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 100, 'tier' => 10, 'position' => 2]);

    $target->refresh()->load('steps');

    expect($target->steps->sum(fn ($s) => $s->xpRequired()))->toBe(533_862)
        ->and($target->creditsRequired())->toBe(9_500_000);
});

/**
 * The blueprint-discounted figure wins when entered; without one the API's full
 * price stands in, so a step is never silently free.
 */
it('prefers the discounted figure over the full price', function () {
    $step = new WotGrindStep(['research_xp' => 189_000, 'research_xp_remaining' => 149_310]);
    expect($step->researchCost())->toBe(149_310);

    $step = new WotGrindStep(['research_xp' => 189_000, 'research_xp_remaining' => null]);
    expect($step->researchCost())->toBe(189_000);
});

it('subtracts banked XP from what is left', function () {
    $step = new WotGrindStep([
        'research_xp' => 149_310, 'research_xp_remaining' => 149_310,
        'module_xp_remaining' => 0, 'banked_xp' => 83_305,
    ]);

    // The spreadsheet's ST-I row, to the digit.
    expect($step->xpRemaining())->toBe(66_005)
        ->and($step->progress)->toBe(55.8);
});

it('never reports negative remaining XP', function () {
    $step = new WotGrindStep(['research_xp_remaining' => 1000, 'banked_xp' => 5000]);

    expect($step->xpRemaining())->toBe(0)
        ->and($step->progress)->toBe(100.0);
});

it('edits a step and shows it on the board', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);
    $step = WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 90, 'tier' => 9,
        'position' => 0, 'research_xp' => 225_000]);

    $this->actingAs($user)->patch(route('wot.grinding.step', $step), [
        'banked_xp' => 100_000, 'is_active' => true,
    ])->assertRedirect();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->component('Grinding')
        ->has('active', 1)
        ->where('active.0.banked_xp', 100_000)
        ->where('totals.banked_xp', 100_000),
    );
});

it('will not let one account edit another board', function () {
    $mine = User::factory()->create();
    WotAccount::factory()->for($mine)->create();
    $theirs = WotGrindTarget::factory()->create();
    $step = WotGrindStep::create(['wot_grind_target_id' => $theirs->id, 'tank_id' => 1, 'tier' => 9, 'position' => 0]);

    $this->actingAs($mine)->patch(route('wot.grinding.step', $step), ['banked_xp' => 5])->assertNotFound();
    $this->actingAs($mine)->delete(route('wot.grinding.destroy', $theirs))->assertNotFound();

    expect($step->fresh()->banked_xp)->toBe(0);
});

it('toggles a target complete and back', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create();

    $this->actingAs($user)->patch(route('wot.grinding.complete', $target));
    expect($target->fresh()->is_complete)->toBeTrue();

    $this->actingAs($user)->patch(route('wot.grinding.complete', $target));
    expect($target->fresh()->is_complete)->toBeFalse();
});

it('excludes completed targets from the outstanding totals', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $done = WotGrindTarget::factory()->for($account, 'account')->completed()->create(['tank_id' => $ten->tank_id]);
    WotGrindStep::create(['wot_grind_target_id' => $done->id, 'tank_id' => 90, 'tier' => 9, 'position' => 0,
        'research_xp' => 225_000, 'research_xp_remaining' => 225_000]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('totals.targets', 1)
        ->where('totals.open', 0)
        // xp_required, not xp_remaining: the latter is the whole tree's figure
        // now, the way credits_required already was, so a completed target no
        // longer moves it.
        ->where('totals.xp_required', 0),
    );
});

it('stores planning settings', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.settings'), [
        'credits_available' => 59_780_000, 'garage_slots_vacant' => 45,
    ])->assertRedirect();

    expect(WotGrindSetting::where('wot_account_id', $account->id)->first()->credits_available)->toBe(59_780_000);
});

it('rejects nonsense on the manual fields', function (array $payload) {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create();
    $step = WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 1, 'tier' => 9, 'position' => 0]);

    $this->actingAs($user)->patch(route('wot.grinding.step', $step), $payload)->assertSessionHasErrors();
})->with([
    'negative banked' => [['banked_xp' => -1]],
    'too many fragments' => [['blueprint_fragments' => 5000]],
]);

it('offers only researchable vehicles as new targets', function () {
    [$eight, $nine, $ten] = techLine();
    WotVehicle::factory()->premium()->create(['tank_id' => 500, 'name' => 'Premium', 'tier' => 8]);
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(function ($page) {
        $ids = collect($page->toArray()['props']['options'])->pluck('tank_id');

        // The premium is excluded, and so is the target already tracked.
        expect($ids)->not->toContain(500)->not->toContain(100)
            ->and($ids)->toContain(90);
    });
});

// --- Module research ----------------------------------------------------------

function moduleStep(): array
{
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    WotVehicle::factory()->create(['tank_id' => 900, 'name' => 'Grinder', 'tier' => 9]);
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => 900]);
    $step = WotGrindStep::create([
        'wot_grind_target_id' => $target->id, 'tank_id' => 900, 'tier' => 9, 'position' => 0,
        'research_xp' => 200_000, 'banked_xp' => 100_000,
    ]);

    WotVehicleModule::insert([
        ['module_id' => 1, 'tank_id' => 900, 'name' => 'Big Gun', 'type' => 'vehicleGun', 'price_xp' => 60_000, 'price_credit' => 0, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ['module_id' => 2, 'tank_id' => 900, 'name' => 'Engine', 'type' => 'vehicleEngine', 'price_xp' => 25_000, 'price_credit' => 0, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ['module_id' => 3, 'tank_id' => 900, 'name' => 'Stock Tracks', 'type' => 'vehicleChassis', 'price_xp' => 0, 'price_credit' => 0, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return [$user, $step];
}

it('offers only upgrade modules, never stock ones', function () {
    [$user, $step] = moduleStep();

    expect($step->moduleOptions())->toHaveCount(2)
        ->and($step->moduleOptions()->pluck('name'))->not->toContain('Stock Tracks')
        // Outstanding is derived, not stored.
        ->and($step->outstandingModuleXp())->toBe(85_000);
});

/**
 * The whole point of the feature: researching a module spends banked XP in
 * game, so the banked figure should drop by exactly its cost rather than being
 * adjusted by hand.
 */
it('drops banked XP by the module cost when one is researched', function () {
    [$user, $step] = moduleStep();

    $this->actingAs($user)->patch(route('wot.grinding.module', $step), [
        'module_id' => 1, 'researched' => true,
    ])->assertRedirect();

    $step->refresh();

    expect($step->banked_xp)->toBe(40_000)
        ->and($step->module_xp_remaining)->toBe(25_000)
        ->and($step->researched_modules)->toBe([1]);
});

it('gives the XP back when a module is un-ticked', function () {
    [$user, $step] = moduleStep();

    $this->actingAs($user)->patch(route('wot.grinding.module', $step), ['module_id' => 1, 'researched' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.module', $step), ['module_id' => 1, 'researched' => false]);

    $step->refresh();

    expect($step->banked_xp)->toBe(100_000)
        ->and($step->module_xp_remaining)->toBe(85_000)
        ->and($step->researched_modules)->toBe([]);
});

it('ignores a repeated tick', function () {
    [$user, $step] = moduleStep();

    $this->actingAs($user)->patch(route('wot.grinding.module', $step), ['module_id' => 1, 'researched' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.module', $step), ['module_id' => 1, 'researched' => true]);

    // Charged once, not twice.
    expect($step->refresh()->banked_xp)->toBe(40_000);
});

/**
 * A module can legitimately be researched with Free XP, leaving less banked
 * than it cost. Going negative would be nonsense on the page.
 */
it('floors banked XP at zero', function () {
    [$user, $step] = moduleStep();
    $step->update(['banked_xp' => 10_000]);

    $this->actingAs($user)->patch(route('wot.grinding.module', $step), ['module_id' => 1, 'researched' => true]);

    expect($step->refresh()->banked_xp)->toBe(0);
});

it('refuses a module that belongs to another vehicle', function () {
    [$user, $step] = moduleStep();
    WotVehicleModule::insert([['module_id' => 99, 'tank_id' => 555, 'name' => 'Elsewhere',
        'type' => 'vehicleGun', 'price_xp' => 50_000, 'price_credit' => 0, 'is_default' => false,
        'created_at' => now(), 'updated_at' => now()]]);

    $this->actingAs($user)->patch(route('wot.grinding.module', $step), ['module_id' => 99, 'researched' => true]);

    expect($step->refresh()->banked_xp)->toBe(100_000)
        ->and($step->researched_modules)->toBeNull();
});

it('will not let one account tick another board modules', function () {
    [$user, $step] = moduleStep();
    $other = User::factory()->create();
    WotAccount::factory()->for($other)->create();

    $this->actingAs($other)->patch(route('wot.grinding.module', $step), ['module_id' => 1, 'researched' => true])
        ->assertNotFound();

    expect($step->refresh()->banked_xp)->toBe(100_000);
});

it('falls back to the stored figure when a vehicle has no module rows', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    WotVehicle::factory()->create(['tank_id' => 901, 'tier' => 9]);
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => 901]);
    $step = WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 901, 'tier' => 9,
        'position' => 0, 'module_xp_remaining' => 42_000]);

    // Without this, a vehicle the encyclopedia hasn't described would read as
    // fully upgraded.
    expect($step->outstandingModuleXp())->toBe(42_000)
        ->and($step->moduleOptions())->toBeEmpty();
});

/**
 * The game's own tech-tree order, which is neither alphabetical nor by size —
 * so it has to be asserted, not assumed.
 */
it('sorts vehicle lists by the tech-tree nation order', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    // Deliberately created back-to-front.
    foreach ([['italy', 1], ['usa', 2], ['poland', 3], ['germany', 4], ['ussr', 5]] as [$nation, $id]) {
        WotVehicle::factory()->create(['tank_id' => $id, 'name' => "Tank {$id}", 'tier' => 10, 'nation' => $nation]);
        WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $id]);
    }

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('targets.0.nation', 'usa')
        ->where('targets.1.nation', 'germany')
        ->where('targets.2.nation', 'ussr')
        ->where('targets.3.nation', 'poland')
        ->where('targets.4.nation', 'italy'),
    );
});

it('sinks completed targets below the nation order', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    WotVehicle::factory()->create(['tank_id' => 1, 'nation' => 'usa', 'tier' => 10]);
    WotVehicle::factory()->create(['tank_id' => 2, 'nation' => 'italy', 'tier' => 10]);
    // USA would normally lead, but a finished target is out of the working list.
    WotGrindTarget::factory()->for($account, 'account')->completed()->create(['tank_id' => 1]);
    WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => 2]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('targets.0.nation', 'italy')
        ->where('targets.1.nation', 'usa'),
    );
});

it('sorts an unknown nation last rather than first', function () {
    expect(WotVehicle::rankOf('usa'))->toBe(0)
        ->and(WotVehicle::rankOf('italy'))->toBe(10)
        // A nation added by a future patch must not displace the known ones.
        ->and(WotVehicle::rankOf('atlantis'))->toBe(11)
        ->and(WotVehicle::rankOf(null))->toBe(11);
});

it('totals the active grinding columns', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);

    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 80, 'tier' => 8,
        'position' => 0, 'research_xp' => 149_400, 'module_xp_remaining' => 30_000,
        'banked_xp' => 40_000, 'is_active' => true]);
    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 90, 'tier' => 9,
        'position' => 1, 'research_xp' => 225_000, 'module_xp_remaining' => 20_000,
        'banked_xp' => 5_000, 'is_active' => true]);
    // Not active — must stay out of every column.
    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 100, 'tier' => 10,
        'position' => 2, 'banked_xp' => 999_999, 'module_xp_remaining' => 999_999]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('totals.active.steps', 2)
        ->where('totals.active.banked_xp', 45_000)
        ->where('totals.active.module_xp_remaining', 50_000)
        ->where('totals.active.research_cost', 374_400)
        ->where('totals.active.xp_required', 424_400)
        // (149,400 + 30,000 - 40,000) + (225,000 + 20,000 - 5,000)
        ->where('totals.active.xp_remaining', 379_400)
        // 45,000 banked of 424,400 required. Free XP is no longer subtracted
        // here — it is a statement about modules now, and a planned module is
        // still XP that has to be found.
        ->where('totals.active.progress', 10.6),
    );
});

/**
 * Averaging the per-row percentages would let a tiny grind pull as hard on the
 * figure as a tier 10 one. Here row one is 100% and row two 0%; an average says
 * 50%, the weighted figure says 1%.
 */
it('weights total progress by XP rather than averaging the rows', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);

    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 80, 'tier' => 8,
        'position' => 0, 'research_xp' => 1_000, 'banked_xp' => 1_000, 'is_active' => true]);
    WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => 90, 'tier' => 9,
        'position' => 1, 'research_xp' => 99_000, 'banked_xp' => 0, 'is_active' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('totals.active.progress', 1),
    );
});

it('reports full progress when nothing is active', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('totals.active.steps', 0)
        ->where('totals.active.xp_required', 0)
        ->where('totals.active.progress', 100),
    );
});

/**
 * A three-tier line with the tier VIII in the garage.
 *
 * The purchase board is built from the tech tree rather than from targets, so
 * the steps here matter to the other tabs, not this one. What this line owns is
 * decided by what has been played — the tier VIII stands in for "the vehicle
 * being ground in", which is what its position-zero step used to mean.
 */
function purchaseLine(User $user): array
{
    [$eight, $nine, $ten] = techLine();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);

    foreach ([[80, 8, 0], [90, 9, 1], [100, 10, 2]] as [$tank, $tier, $position]) {
        WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => $tank,
            'tier' => $tier, 'position' => $position]);
    }

    played($account, $eight->tank_id);

    return [$account, $target, $ten];
}

it('lays the purchase board out as one column per tier', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // Every tier holding a cell gets a column. Tier VIII is the vehicle
        // being ground in and the only one at that tier, so it is settled — it
        // still renders, and starts filtered off rather than being dropped.
        ->where('purchase.tiers', [8, 9, 10])
        ->where('purchase.bought_tiers', [8])
        ->has('purchase.rows', 1)
        // Named by the row's tier X vehicle, so its type comes along too.
        ->where('purchase.rows.0.type', 'mediumTank')
        ->where('purchase.rows.0.cells.8.is_purchased', true)
        ->where('purchase.rows.0.cells.9.is_purchased', false)
        ->where('purchase.rows.0.cells.9.is_unlocked', false)
        ->where('purchase.rows.0.cells.9.price', 3_400_000)
        ->where('purchase.rows.0.cells.10.price', 6_100_000)
        // The owned vehicle is not an outlay.
        ->where('purchase.rows.0.credits_remaining', 9_500_000)
        ->where('totals.credits_required', 9_500_000),
    );
});

it('unlocks a tank and then buys it', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_unlocked' => true])->assertRedirect();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.9.is_unlocked', true)
        ->where('purchase.rows.0.cells.9.is_purchased', false)
        // Unlocking costs XP, not credits — the bill is unchanged.
        ->where('purchase.rows.0.credits_remaining', 9_500_000),
    );

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_purchased' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.9.is_purchased', true)
        ->where('purchase.rows.0.credits_remaining', 6_100_000),
    );
});

it('treats a bought tank as researched even if it was never unlocked', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_purchased' => true]);

    expect(WotTankPurchase::where('tank_id', 90)->first()->is_unlocked)->toBeTrue();
});

/**
 * The mirror of the rule above, and the one that actually bit: tier VIII reads
 * as bought because it is in the garage, so there is no stored is_unlocked
 * behind it to fall back on. Un-buying used to write is_purchased false and
 * leave is_unlocked at its default, dropping the cell two steps to unresearched
 * instead of one to researched-but-unbought.
 */
it('leaves a tank researched when it is marked as not bought', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.8.is_purchased', true)
        ->where('purchase.rows.0.cells.8.is_unlocked', true),
    );

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 80), ['is_purchased' => false]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.8.is_purchased', false)
        ->where('purchase.rows.0.cells.8.is_unlocked', true),
    );

    expect(WotTankPurchase::where('tank_id', 80)->first()->is_unlocked)->toBeTrue();
});

/**
 * Marking a vehicle bought says something about that vehicle and nothing else.
 * Owning a tier X does imply having owned the IX to research past it, but that
 * is inferred from having *played* the X — a tick is a statement you made, and
 * spreading it down the line would put words in your mouth about tanks you may
 * well have sold.
 */
it('does not mark the tiers below a vehicle you tick as bought', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 100), ['is_purchased' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // The line stays on the board — it is a record of what you own, and it
        // is the filter's job to hide it, not the server's.
        ->has('purchase.rows', 1)
        ->where('purchase.rows.0.cells.10.is_purchased', true)
        // The tier VIII is in the garage, so it was owned all along.
        ->where('purchase.rows.0.cells.8.is_purchased', true)
        // The tier IX is not, and ticking the X above it does not change that.
        ->where('purchase.rows.0.cells.9.is_purchased', false)
        ->where('purchase.rows.0.credits_remaining', 3_400_000)
        ->where('totals.credits_required', 3_400_000),
    );
});

/**
 * The other half: what you have played still settles what sits under it, which
 * is how a line researched past long ago reads as owned without ticking every
 * tier by hand.
 */
it('settles the tiers below a vehicle that has been played', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    played($account, $ten->tank_id);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.9.is_purchased', true)
        ->where('purchase.rows.0.cells.8.is_purchased', true)
        ->where('purchase.rows.0.credits_remaining', 0),
    );

    // ...and saying you sold one still sticks.
    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_purchased' => false]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.9.is_purchased', false)
        ->where('purchase.rows.0.credits_remaining', 3_400_000),
    );
});

it('takes a discounted price over the shop price and gives it back', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 100), ['price_credit' => 3_050_000]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.10.price', 3_050_000)
        ->where('purchase.rows.0.cells.10.api_price', 6_100_000)
        ->where('purchase.rows.0.cells.10.is_discounted', true)
        ->where('purchase.rows.0.credits_remaining', 6_450_000),
    );

    // Clearing restores the encyclopedia price rather than recording a free tank.
    $this->actingAs($user)->patch(route('wot.grinding.purchase', 100), ['price_credit' => null]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.cells.10.price', 6_100_000)
        ->where('purchase.rows.0.cells.10.is_discounted', false),
    );
});

it('will not let one account mark another account tanks bought', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $intruder = User::factory()->create();
    WotAccount::factory()->for($intruder)->create();

    $this->actingAs($intruder)->patch(route('wot.grinding.purchase', 90), ['is_purchased' => true]);

    // The write lands on the intruder's own board, never on someone else's.
    expect(WotTankPurchase::count())->toBe(1)
        ->and(WotTankPurchase::first()->wot_account_id)->toBe($intruder->wotAccount->id);
});

it('rejects a tank the encyclopedia has never heard of', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 999999), ['is_purchased' => true])
        ->assertNotFound();
});

/**
 * The server used to drop a tier once every line had bought it. It now reports
 * the tier as settled instead and leaves the column in place, because a column
 * that is not rendered is also a column you cannot un-tick anything in. The
 * rule did not go away — it moved to being the filter's default.
 */
it('marks a tier bought once every line has bought that tier', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.tiers', [8, 9, 10])
        ->where('purchase.bought_tiers', [8]));

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_purchased' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // The column stays; only its default visibility changes.
        ->where('purchase.tiers', [8, 9, 10])
        ->where('purchase.bought_tiers', [8, 9])
        // Settling a tier must not drop the money: the tier X is still owed.
        ->where('totals.credits_required', 6_100_000),
    );
});

/**
 * A path is truncated at the vehicle being played, so the tiers below it are
 * absent from its steps. They are not absent from the player's history — you
 * cannot reach a tier IX without researching the VIII — so they belong in the
 * matrix as bought, not as empty cells.
 */
it('fills in the tiers a line has already researched past', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $ten = WotVehicle::factory()->create(['tank_id' => 100, 'name' => 'Long X', 'short_name' => 'Lng X',
        'tier' => 10, 'nation' => 'ussr', 'price_credit' => 6_100_000, 'next_tanks' => null]);
    $otherTen = WotVehicle::factory()->create(['tank_id' => 101, 'name' => 'Short X', 'short_name' => 'Shrt X',
        'tier' => 10, 'nation' => 'ussr', 'price_credit' => 6_100_000, 'next_tanks' => null]);
    $nine = WotVehicle::factory()->create(['tank_id' => 90, 'tier' => 9, 'nation' => 'ussr',
        'price_credit' => 3_400_000, 'next_tanks' => [100 => 225_000, 101 => 225_000]]);
    $eight = WotVehicle::factory()->create(['tank_id' => 80, 'tier' => 8, 'nation' => 'ussr',
        'price_credit' => 2_400_000, 'next_tanks' => [90 => 149_400]]);
    WotVehicle::factory()->create(['tank_id' => 70, 'tier' => 7, 'nation' => 'ussr',
        'price_credit' => 1_400_000, 'next_tanks' => [80 => 98_000]]);

    // The long line starts at tier VII, so its tier VIII is a step still to be
    // bought — which is what keeps that column on the board at all.
    $long = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => 100]);
    foreach ([[70, 7, 0], [80, 8, 1], [90, 9, 2], [100, 10, 3]] as [$tank, $tier, $position]) {
        WotGrindStep::create(['wot_grind_target_id' => $long->id, 'tank_id' => $tank,
            'tier' => $tier, 'position' => $position]);
    }

    // The short line branches off the same tier IX and has no steps below it.
    $short = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => 101]);
    foreach ([[90, 9, 0], [101, 10, 1]] as [$tank, $tier, $position]) {
        WotGrindStep::create(['wot_grind_target_id' => $short->id, 'tank_id' => $tank,
            'tier' => $tier, 'position' => $position]);
    }

    played($account, 70);

    // Same nation and tier, so rows come back in name order — and the name a
    // row sorts by is its tier X's short form: Lng X, Shrt X.
    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // Tier VII is in the garage, so it is settled and starts filtered off;
        // tier VIII is still owed and holds its column open.
        ->where('purchase.tiers', [7, 8, 9, 10])
        ->where('purchase.bought_tiers', [7])
        ->where('purchase.rows.0.name', 'Lng X')
        ->where('purchase.rows.1.name', 'Shrt X')
        // Both lines run through the same tier VIII, and ownership is a fact
        // about the tank rather than about the line, so they agree on it.
        ->where('purchase.rows.0.cells.8.tank_id', 80)
        ->where('purchase.rows.1.cells.8.tank_id', 80)
        ->where('purchase.rows.0.cells.8.is_purchased', false)
        ->where('purchase.rows.1.cells.8.is_purchased', false)
        // The first row on screen keeps it; the other carries it read-only.
        ->where('purchase.rows.0.cells.8.is_shared', false)
        ->where('purchase.rows.1.cells.8.is_shared', true)
        ->where('purchase.rows.1.cells.8.shared_with', 'Lng X')
        // Both lines branch off the same tier IX as well, so this row owes only
        // its own tier X — everything below is on the first line's bill.
        ->where('purchase.rows.1.credits_remaining', 6_100_000),
    );
});

/** Marks a vehicle as played, which is how the board infers ownership. */
function played(WotAccount $account, int $tankId): void
{
    WotVehicleSnapshot::create([
        'wot_account_id' => $account->id, 'tank_id' => $tankId,
        'captured_at' => now(), 'battles' => 10, 'statistics' => ['battles' => 10],
    ]);
}

/**
 * A line is known by its tier X in game and in every community tool, so that is
 * the name the row carries — including when the line runs on to a tier XI and
 * the row is headed by that instead.
 */
it('names a purchase row after its tier X, not the vehicle that heads it', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    WotVehicle::factory()->create(['tank_id' => 110, 'name' => 'Top Eleven', 'short_name' => 'Top XI',
        'tier' => 11, 'price_credit' => 10_000_000, 'next_tanks' => null]);
    $ten->update(['next_tanks' => [110 => 300_000]]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 1)
        // Headed by the XI, which is where the line now ends...
        ->where('purchase.rows.0.key', 'l110')
        ->where('purchase.rows.0.cells.11.tank_id', 110)
        // ...and still named for its tier X, by the short form.
        ->where('purchase.rows.0.name', 'Tgt X'),
    );
});

/**
 * Two tracked tier X lines that genuinely share their tier VII and VIII.
 *
 * The shape the whole sharing rule exists for. Both lines start at the VII, so
 * the VIII above it is a step still to be bought on each — which is what makes
 * the double-count visible in credits rather than hidden behind cells that were
 * already owned. Same nation and tier, so the sort falls through to the name
 * and the owning row is deterministic: 'A X' before 'B X'.
 */
function branchedLines(User $user): array
{
    $account = WotAccount::factory()->for($user)->create();

    WotVehicle::factory()->create(['tank_id' => 70, 'short_name' => 'Base VII', 'tier' => 7,
        'nation' => 'ussr', 'price_credit' => 1_400_000, 'next_tanks' => [80 => 98_000]]);
    WotVehicle::factory()->create(['tank_id' => 80, 'short_name' => 'Shared VIII', 'tier' => 8,
        'nation' => 'ussr', 'price_credit' => 2_400_000, 'next_tanks' => [90 => 149_400, 91 => 149_400]]);
    WotVehicle::factory()->create(['tank_id' => 90, 'short_name' => 'A IX', 'tier' => 9,
        'nation' => 'ussr', 'price_credit' => 3_400_000, 'next_tanks' => [100 => 225_000]]);
    WotVehicle::factory()->create(['tank_id' => 91, 'short_name' => 'B IX', 'tier' => 9,
        'nation' => 'ussr', 'price_credit' => 3_400_000, 'next_tanks' => [101 => 225_000]]);
    WotVehicle::factory()->create(['tank_id' => 100, 'short_name' => 'A X', 'tier' => 10,
        'nation' => 'ussr', 'price_credit' => 6_100_000, 'next_tanks' => null]);
    WotVehicle::factory()->create(['tank_id' => 101, 'short_name' => 'B X', 'tier' => 10,
        'nation' => 'ussr', 'price_credit' => 6_100_000, 'next_tanks' => null]);

    foreach ([[100, [70, 80, 90, 100]], [101, [70, 80, 91, 101]]] as [$targetTank, $path]) {
        $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $targetTank]);

        foreach ($path as $position => $tank) {
            WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => $tank,
                'tier' => (int) floor($tank / 10), 'position' => $position]);
        }
    }

    // Both lines start from a tier VII already in the garage, so the shared
    // tier VIII above it is the first thing either of them still has to buy.
    played($account, 70);

    return [$account];
}

/**
 * A tank on two lines is one purchase. The first row on screen keeps it as the
 * editable cell; the rest carry it read-only and name where it lives, so the
 * same vehicle is never two sets of buttons.
 */
it('lets one row own a shared vehicle and shows it as text on the others', function () {
    $user = User::factory()->create();
    branchedLines($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 2)
        ->where('purchase.rows.0.name', 'A X')
        ->where('purchase.rows.1.name', 'B X')
        // The first row owns both shared tiers outright.
        ->where('purchase.rows.0.cells.7.is_shared', false)
        ->where('purchase.rows.0.cells.8.is_shared', false)
        ->where('purchase.rows.0.cells.8.shared_with', null)
        // The second carries them, pointing at where they are counted.
        ->where('purchase.rows.1.cells.7.is_shared', true)
        ->where('purchase.rows.1.cells.8.is_shared', true)
        ->where('purchase.rows.1.cells.8.shared_with', 'A X')
        // Its own tiers are untouched.
        ->where('purchase.rows.1.cells.9.is_shared', false)
        ->where('purchase.rows.1.cells.10.is_shared', false),
    );
});

/**
 * The regression this rule exists for, and one that was already latent: the
 * shared tier VIII is unbought on both lines, so before claiming it was summed
 * twice and the board asked for 2,400,000 credits that do not exist.
 */
it('bills a shared vehicle to one row only', function () {
    $user = User::factory()->create();
    branchedLines($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // VIII + IX + X. The VII is position zero, so it is already owned.
        ->where('purchase.rows.0.credits_remaining', 11_900_000)
        // IX + X only: the VII and VIII are on the row above.
        ->where('purchase.rows.1.credits_remaining', 9_500_000)
        // 21.4M, not the 23.8M that counting the shared VIII twice would give.
        ->where('totals.credits_required', 21_400_000),
    );
});

/**
 * Claiming runs after the sort, so "first" means first on screen rather than
 * first built. Renaming the tier Xs flips which row you meet first, and the
 * editable copy has to move with it.
 */
it('claims a shared vehicle for the row that appears first', function () {
    $user = User::factory()->create();
    branchedLines($user);

    WotVehicle::where('tank_id', 100)->update(['short_name' => 'Z X']);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.rows.0.name', 'B X')
        ->where('purchase.rows.1.name', 'Z X')
        ->where('purchase.rows.0.cells.8.is_shared', false)
        ->where('purchase.rows.1.cells.8.is_shared', true)
        ->where('purchase.rows.1.cells.8.shared_with', 'B X')
        // The bill is the same money, just billed to the other line.
        ->where('purchase.rows.0.credits_remaining', 11_900_000)
        ->where('totals.credits_required', 21_400_000),
    );
});

/**
 * A settled line still shows every tier it shares with a line that is not
 * settled — and it owns none of them. Ownership goes to a row that still has to
 * pay: if the settled line took the shared VIII by sorting first, neither row
 * would carry its price and the money would drop off the board.
 */
it('never lets an owned line claim a shared vehicle from one that still owes', function () {
    $user = User::factory()->create();
    [$account] = branchedLines($user);

    // Played rather than ticked: play history is what settles the tiers under a
    // vehicle, so this is what actually leaves line A owning nothing.
    played($account, 100);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // Both lines are on the board now; line A is simply settled.
        ->has('purchase.rows', 2)
        ->where('purchase.rows.0.name', 'A X')
        ->where('purchase.rows.0.credits_remaining', 0)
        // B still owes the shared VII and VIII, so B holds them.
        ->where('purchase.rows.1.name', 'B X')
        ->where('purchase.rows.1.cells.8.is_shared', false)
        ->where('purchase.rows.1.credits_remaining', 11_900_000)
        // A carries them read-only, pointing at the row that pays.
        ->where('purchase.rows.0.cells.8.is_shared', true)
        ->where('purchase.rows.0.cells.8.shared_with', 'B X')
        ->where('totals.credits_required', 11_900_000),
    );
});

it('leaves out premiums, which sit outside the research tree', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    played($account, $nine->tank_id);

    WotVehicle::factory()->create(['tank_id' => 102, 'name' => 'Shop X', 'tier' => 10,
        'is_premium' => true, 'price_credit' => 6_100_000, 'next_tanks' => null]);
    $nine->update(['next_tanks' => [100 => 225_000, 102 => 225_000]]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 1)
        ->where('purchase.rows.0.name', 'Tgt X'),
    );
});

/**
 * The floor decides what counts as a line, which matters because the tree is
 * littered with low-tier vehicles that unlock nothing and are branch tops only
 * in the technical sense.
 */
it('honours the tier floor when deciding what is a line', function () {
    config()->set('wargaming.line_min_tier', 11);

    techLine();
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->get(route('wot.grinding'))
        ->assertInertia(fn ($page) => $page->has('purchase.rows', 0));
});

/**
 * The board is the tech tree, not a projection of what you are grinding. Every
 * line gets a row whether you have tracked it, started it, or never touched it
 * — reducing that to what you care about is the filters' job.
 */
it('gives every line in the tree a row, tracked or not', function () {
    techLine();
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    // A second, unrelated line nobody has tracked or played.
    WotVehicle::factory()->create(['tank_id' => 200, 'short_name' => 'Other X', 'tier' => 10,
        'price_credit' => 6_100_000, 'next_tanks' => null]);
    WotVehicle::factory()->create(['tank_id' => 190, 'short_name' => 'Other IX', 'tier' => 9,
        'price_credit' => 3_400_000, 'next_tanks' => [200 => 225_000]]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 2)
        // Nothing played, so nothing is owned and the full tree is the bill.
        ->where('totals.credits_required', 21_400_000),
    );
});

/**
 * Collector's vehicles are bought outright rather than researched, so nothing
 * in the tree unlocks them and they lead nowhere: the 113, both AMX 30s, the
 * Jagdpanther II and the T-62A all sit with neither a predecessor nor a
 * successor. That makes them a one-cell row with no path behind it, which is
 * not a line and not something to plan a grind around.
 *
 * Detected structurally because the encyclopedia publishes no flag for it —
 * is_premium is false for every one of them.
 */
it('leaves out a vehicle nothing researches into', function () {
    techLine();
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    // Reachable by nothing, leading nowhere — and not flagged premium.
    WotVehicle::factory()->create(['tank_id' => 300, 'short_name' => 'Collector X', 'tier' => 10,
        'is_premium' => false, 'price_credit' => 6_100_000, 'next_tanks' => null]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // Only the real line; the orphan is not one.
        ->has('purchase.rows', 1)
        ->where('purchase.rows.0.name', 'Tgt X')
        ->where('totals.credits_required', 11_900_000),
    );
});

/**
 * A line you have finished still belongs on the board — it is a record of the
 * tree, and hiding it is the Owned filter's decision rather than the server's.
 */
it('shows a line you own outright, owing nothing', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();
    played($account, $ten->tank_id);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 1)
        // Owning the top means the tiers under it were owned to reach it.
        ->where('purchase.rows.0.cells.8.is_purchased', true)
        ->where('purchase.rows.0.cells.9.is_purchased', true)
        ->where('purchase.rows.0.cells.10.is_purchased', true)
        ->where('purchase.rows.0.credits_remaining', 0)
        ->where('purchase.bought_tiers', [8, 9, 10])
        ->where('totals.credits_required', 0),
    );
});

/**
 * Targets drive the other tabs. They used to decide what this one showed too,
 * which is why a line you owned but never tracked had no row at all.
 */
it('builds the purchase board without reference to grind targets', function () {
    [$eight, $nine, $ten] = techLine();
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 1)
        ->where('purchase.rows.0.credits_remaining', 11_900_000),
    );

    // Tracking it changes the other tabs, and must change nothing here.
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);
    foreach ([[80, 8, 0], [90, 9, 1], [100, 10, 2]] as [$tank, $tier, $position]) {
        WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => $tank,
            'tier' => $tier, 'position' => $position]);
    }

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 1)
        ->where('purchase.rows.0.credits_remaining', 11_900_000),
    );
});

it('remembers where the purchase filters were left', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), [
        'board' => 'purchase',
        'hidden_nations' => ['usa', 'japan'],
        'hidden_tiers' => [1, 2, 3],
        'hide_owned' => false,
        'show_sale' => true,
    ])->assertNoContent();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('settings.purchase_filters.hidden_nations', ['usa', 'japan'])
        ->where('settings.purchase_filters.hidden_tiers', [1, 2, 3])
        ->where('settings.purchase_filters.hide_owned', false)
        ->where('settings.purchase_filters.show_sale', true),
    );
});

/**
 * The client debounces one filter at a time, so a payload carrying a single key
 * must not read as "the other three were cleared".
 */
it('merges a partial filter payload into what is stored', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), [
        'board' => 'purchase', 'hidden_nations' => ['ussr'], 'show_sale' => true,
    ]);
    $this->actingAs($user)->patch(route('wot.grinding.filters'), ['board' => 'purchase', 'hidden_tiers' => [4]]);

    // toEqual, not toBe: the stored key order follows whichever request wrote
    // each key, and nothing reads these positionally.
    expect(WotGrindSetting::where('wot_account_id', $account->id)->first()->purchase_filters)
        ->toEqual(['hidden_nations' => ['ussr'], 'show_sale' => true, 'hidden_tiers' => [4]]);
});

/**
 * Null is load-bearing: it is what tells the board to seed the tier filter from
 * the tiers already bought out, rather than from a saved selection.
 */
it('reports no stored filters until some are saved', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(
        fn ($page) => $page->where('settings.purchase_filters', null),
    );
});

it('rejects a filter value the board could never produce', function (array $payload) {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), ['board' => 'purchase', ...$payload])
        ->assertSessionHasErrors();
})->with([
    'unknown nation' => [['hidden_nations' => ['atlantis']]],
    'tier above the tree' => [['hidden_tiers' => [12]]],
    'tier below the tree' => [['hidden_tiers' => [0]]],
    'nations as a scalar' => [['hidden_nations' => 'usa']],
]);

it('refuses filters for a board that does not exist', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), ['board' => 'blueprints', 'hide_owned' => true])
        ->assertSessionHasErrors('board');
});

it('saves an empty filter set as showing everything', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), [
        'board' => 'purchase', 'hidden_nations' => [], 'hidden_tiers' => [],
    ])->assertNoContent();

    // Distinct from never having saved, which is null and seeds from
    // bought_tiers instead.
    expect(WotGrindSetting::where('wot_account_id', $account->id)->first()->purchase_filters)
        ->toBe(['hidden_nations' => [], 'hidden_tiers' => []]);
});

it('will not store filters for a user with no linked account', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('wot.grinding.filters'), ['board' => 'purchase', 'hide_owned' => true])
        ->assertNotFound();
});

/**
 * The tech line from techLine() with upgrade modules on every tier, which is
 * what the Free XP board is a grid of.
 */
function freeXpLine(User $user): WotAccount
{
    [$eight, $nine, $ten] = techLine();
    $account = WotAccount::factory()->for($user)->create();

    $rows = [];

    foreach ([[80, 100, 40_000], [90, 200, 90_000], [100, 300, 150_000]] as [$tank, $module, $xp]) {
        $rows[] = ['module_id' => $module, 'tank_id' => $tank, 'name' => "Gun {$tank}", 'type' => 'vehicleGun', 'price_xp' => $xp, 'price_credit' => 0, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()];
        $rows[] = ['module_id' => $module + 1, 'tank_id' => $tank, 'name' => "Engine {$tank}", 'type' => 'vehicleEngine', 'price_xp' => 10_000, 'price_credit' => 0, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()];
        // Stock: fitted from the start, so it must never be offerable.
        $rows[] = ['module_id' => $module + 2, 'tank_id' => $tank, 'name' => "Stock {$tank}", 'type' => 'vehicleChassis', 'price_xp' => 0, 'price_credit' => 0, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()];
    }

    WotVehicleModule::insert($rows);

    return $account;
}

it('lays the Free XP board out as one column per tier', function () {
    $user = User::factory()->create();
    freeXpLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.tiers', [8, 9, 10])
        ->has('freexp.rows', 1)
        // Named by the line's tier X, like every other board here.
        ->where('freexp.rows.0.name', 'Tgt X')
        ->where('freexp.rows.0.type', 'mediumTank')
        // Upgrades only — the stock chassis is not something Free XP buys.
        ->has('freexp.rows.0.cells.9.modules', 2)
        ->where('freexp.rows.0.cells.9.total_xp', 100_000)
        // Nothing planned yet, which is not the same as nothing to plan.
        ->where('freexp.rows.0.cells.9.planned_xp', 0)
        ->where('freexp.rows.0.planned_xp', 0)
        ->where('totals.free_xp_planned', 0),
    );
});

it('plans a module and totals what it costs', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), [
        'module_id' => 200, 'planned' => true,
    ])->assertRedirect();

    expect(WotTankModule::where('wot_account_id', $account->id)->first()->planned_module_ids)->toBe([200]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.rows.0.cells.9.planned_xp', 90_000)
        ->where('freexp.rows.0.planned_xp', 90_000)
        // The board's own total and the headline card are one figure.
        ->where('freexp.free_xp_planned', 90_000)
        ->where('totals.free_xp_planned', 90_000),
    );
});

it('takes a module back off the plan', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 200, 'planned' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 201, 'planned' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 200, 'planned' => false]);

    expect(WotTankModule::where('wot_account_id', $account->id)->first()->planned_module_ids)->toBe([201]);
});

/**
 * A stock module is fitted from the start, so planning Free XP against it is
 * meaningless — and it never appears in the dropdown, so a row that accepted it
 * could never be un-ticked again.
 */
it('refuses to plan a module that is not an upgrade on that vehicle', function (int $tankId, int $moduleId) {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', $tankId), [
        'module_id' => $moduleId, 'planned' => true,
    ])->assertRedirect();

    expect(WotTankModule::where('wot_account_id', $account->id)->count())->toBe(0);
})->with([
    'stock module' => [90, 202],
    'a module on another tank' => [90, 100],
    'no such module' => [90, 999],
]);

it('rejects a Free XP plan for a tank the encyclopedia has never heard of', function () {
    $user = User::factory()->create();
    freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 4242), [
        'module_id' => 200, 'planned' => true,
    ])->assertNotFound();
});

it('will not let one account plan another account modules', function () {
    $owner = User::factory()->create();
    $account = freeXpLine($owner);

    $intruder = User::factory()->create();
    WotAccount::factory()->for($intruder)->create(['account_id' => 999_999]);

    $this->actingAs($intruder)->patch(route('wot.grinding.module-plan', 90), [
        'module_id' => 200, 'planned' => true,
    ])->assertRedirect();

    expect(WotTankModule::where('wot_account_id', $account->id)->count())->toBe(0);
});

/**
 * A tank on two lines is one tank with one plan. Counting it on both rows would
 * put the same XP in the grand total twice.
 */
it('counts a shared vehicle on one row only', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    /*
     * A second tier X off the same tier IX, so the VIII and IX sit on both.
     *
     * Same nation as the first, deliberately: rows sort by nation before name,
     * and WotVehicleFactory picks a nation at random, so leaving it to the
     * factory made which row came first a coin flip. Pinned, the order is
     * decided by name, which is what this test is about.
     */
    $nation = WotVehicle::where('tank_id', 100)->value('nation');
    WotVehicle::factory()->create(['tank_id' => 101, 'name' => 'Other X', 'short_name' => 'Oth X', 'tier' => 10, 'type' => 'heavyTank', 'nation' => $nation, 'next_tanks' => null]);
    WotVehicle::where('tank_id', 90)->update(['next_tanks' => json_encode([100 => 225_000, 101 => 240_000])]);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 200, 'planned' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('freexp.rows', 2)
        // 'Oth X' sorts before 'Tgt X', so it meets the shared cells first.
        ->where('freexp.rows.0.name', 'Oth X')
        ->where('freexp.rows.0.cells.9.is_shared', false)
        ->where('freexp.rows.0.planned_xp', 90_000)
        ->where('freexp.rows.1.cells.9.is_shared', true)
        ->where('freexp.rows.1.cells.9.shared_with', 'Oth X')
        ->where('freexp.rows.1.planned_xp', 0)
        // Counted once, not twice.
        ->where('totals.free_xp_planned', 90_000),
    );
});

it('remembers the Free XP filters separately from the purchase ones', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), [
        'board' => 'purchase', 'hidden_nations' => ['usa'],
    ])->assertNoContent();
    $this->actingAs($user)->patch(route('wot.grinding.filters'), [
        'board' => 'freexp', 'hidden_nations' => ['ussr'], 'only_planned' => true,
    ])->assertNoContent();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('settings.purchase_filters.hidden_nations', ['usa'])
        ->where('settings.freexp_filters.hidden_nations', ['ussr'])
        ->where('settings.freexp_filters.only_planned', true),
    );
});

/**
 * The Tiger II's real module tree, which is the shape this feature exists for:
 * the 10.5 cm gun sits behind nothing, but unlocks the Serienturm turret — so
 * dependencies cross slots and the chain is not "every gun".
 */
function gunChainTank(User $user): WotAccount
{
    $account = WotAccount::factory()->for($user)->create();
    WotVehicle::factory()->create(['tank_id' => 100, 'name' => 'Chain X', 'short_name' => 'Chn X', 'tier' => 10, 'nation' => 'germany', 'next_tanks' => null]);
    WotVehicle::factory()->create(['tank_id' => 90, 'name' => 'Chain IX', 'tier' => 9, 'nation' => 'germany', 'next_tanks' => [100 => 225_000]]);

    /*
     * Module ids deliberately nowhere near the rows' primary keys. They were
     * 1..5, which matched the auto-increment ids exactly — and that coincidence
     * made a chain lookup that selected by primary key return the right rows
     * for the wrong reason, hiding the bug until it was run against real data.
     */
    WotVehicleModule::insert([
        // Stock gun, which unlocks the mid gun.
        ['module_id' => 101, 'tank_id' => 90, 'name' => 'Stock Gun', 'type' => 'vehicleGun', 'price_xp' => 0, 'price_credit' => 0, 'is_default' => true, 'next_modules' => json_encode([102]), 'created_at' => now(), 'updated_at' => now()],
        ['module_id' => 102, 'tank_id' => 90, 'name' => 'Mid Gun', 'type' => 'vehicleGun', 'price_xp' => 20_000, 'price_credit' => 0, 'is_default' => false, 'next_modules' => json_encode([103]), 'created_at' => now(), 'updated_at' => now()],
        ['module_id' => 103, 'tank_id' => 90, 'name' => 'Top Gun', 'type' => 'vehicleGun', 'price_xp' => 46_000, 'price_credit' => 0, 'is_default' => false, 'next_modules' => json_encode([104]), 'created_at' => now(), 'updated_at' => now()],
        // Unlocked BY the top gun, so it is not required to reach it.
        ['module_id' => 104, 'tank_id' => 90, 'name' => 'Big Turret', 'type' => 'vehicleTurret', 'price_xp' => 22_000, 'price_credit' => 0, 'is_default' => false, 'next_modules' => null, 'created_at' => now(), 'updated_at' => now()],
        // A separate branch entirely.
        ['module_id' => 105, 'tank_id' => 90, 'name' => 'Big Engine', 'type' => 'vehicleEngine', 'price_xp' => 18_000, 'price_credit' => 0, 'is_default' => false, 'next_modules' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $account;
}

it('reads the top gun chain off the module tree', function () {
    $user = User::factory()->create();
    gunChainTank($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.rows.0.cells.9.top_gun.name', 'Top Gun')
        // The mid gun is on the way; the turret it unlocks and the engine
        // beside it are not.
        ->where('freexp.rows.0.cells.9.top_gun.module_ids', [102, 103])
        ->where('freexp.rows.0.cells.9.top_gun.xp', 66_000)
        ->where('freexp.rows.0.cells.9.top_gun.outstanding', 66_000),
    );
});

it('plans the whole top gun chain in one click', function () {
    $user = User::factory()->create();
    $account = gunChainTank($user);

    $this->actingAs($user)->patch(route('wot.grinding.top-gun', 90))->assertRedirect();

    expect(WotTankModule::where('wot_account_id', $account->id)->first()->planned_module_ids)->toBe([102, 103]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.rows.0.cells.9.planned_xp', 66_000)
        // Nothing left for the button to add, which is what greys it out.
        ->where('freexp.rows.0.cells.9.top_gun.outstanding', 0)
        ->where('totals.free_xp_planned', 66_000),
    );
});

/**
 * The button says "and this too", so it must never take a tick away — losing an
 * unrelated module to a click labelled "plan top gun" would be a much larger
 * claim than the label makes.
 */
it('adds to the plan rather than replacing it', function () {
    $user = User::factory()->create();
    $account = gunChainTank($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 105, 'planned' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.top-gun', 90));

    expect(WotTankModule::where('wot_account_id', $account->id)->first()->planned_module_ids)->toBe([102, 103, 105]);
});

it('counts only what the chain still needs', function () {
    $user = User::factory()->create();
    gunChainTank($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 102, 'planned' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.rows.0.cells.9.top_gun.xp', 66_000)
        // The mid gun is already planned, so only the top gun is outstanding.
        ->where('freexp.rows.0.cells.9.top_gun.outstanding', 46_000),
    );
});

/**
 * Most of tier X is elite on purchase, so its gun is the stock one and there is
 * no chain to offer.
 */
it('offers no top gun where every gun is stock', function () {
    $user = User::factory()->create();
    gunChainTank($user);

    WotVehicleModule::insert([
        ['module_id' => 109, 'tank_id' => 100, 'name' => 'Only Gun', 'type' => 'vehicleGun', 'price_xp' => 0, 'price_credit' => 0, 'is_default' => true, 'next_modules' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(
        fn ($page) => $page->where('freexp.rows.0.cells.10.top_gun', null),
    );

    $this->actingAs($user)->patch(route('wot.grinding.top-gun', 100))->assertNotFound();
});

/**
 * Six vehicles have two guns that neither leads to the other — the KV-4's
 * 107 mm against its 122 mm, the StuG III B's derp against its Pak. The dearer
 * chain is the one a player means by "top gun".
 */
it('picks the dearer chain when two guns both end a branch', function () {
    $user = User::factory()->create();
    gunChainTank($user);

    // A second terminal gun off the mid gun, cheaper than the existing top.
    WotVehicleModule::insert([
        ['module_id' => 106, 'tank_id' => 90, 'name' => 'Derp Gun', 'type' => 'vehicleGun', 'price_xp' => 9_000, 'price_credit' => 0, 'is_default' => false, 'next_modules' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    WotVehicleModule::where('tank_id', 90)->where('module_id', 2)->update(['next_modules' => json_encode([103, 106])]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.rows.0.cells.9.top_gun.name', 'Top Gun')
        ->where('freexp.rows.0.cells.9.top_gun.module_ids', [102, 103]),
    );
});

/**
 * The KV-4's case: its 107 mm unlocks a turret, and that turret unlocks the
 * 122 mm. A one-hop test sees no gun after the 107 mm and calls it the top.
 */
it('looks past a module of another kind to find the real top gun', function () {
    $user = User::factory()->create();
    gunChainTank($user);

    // Mid gun -> turret -> a further gun, so the mid gun is not terminal.
    WotVehicleModule::where('tank_id', 90)->where('module_id', 102)->update(['next_modules' => json_encode([104])]);
    WotVehicleModule::where('tank_id', 90)->where('module_id', 104)->update(['next_modules' => json_encode([103])]);
    WotVehicleModule::where('tank_id', 90)->where('module_id', 103)->update(['next_modules' => null]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('freexp.rows.0.cells.9.top_gun.name', 'Top Gun')
        // The turret is on the way this time, so it is part of the chain.
        ->where('freexp.rows.0.cells.9.top_gun.module_ids', [102, 104, 103])
        ->where('freexp.rows.0.cells.9.top_gun.xp', 88_000),
    );
});

it('will not let one account plan another account top gun', function () {
    $owner = User::factory()->create();
    $account = gunChainTank($owner);

    $intruder = User::factory()->create();
    WotAccount::factory()->for($intruder)->create(['account_id' => 999_999]);

    $this->actingAs($intruder)->patch(route('wot.grinding.top-gun', 90))->assertRedirect();

    expect(WotTankModule::where('wot_account_id', $account->id)->count())->toBe(0);
});

it('lays the XP board out as unlock XP and module XP per cell', function () {
    $user = User::factory()->create();
    freeXpLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.tiers', [8, 9, 10])
        ->has('xp.rows', 1)
        // Each cell points at the next tank on its own line.
        ->where('xp.rows.0.cells.8.unlocks.tank_id', 90)
        ->where('xp.rows.0.cells.8.unlocks.xp', 149_400)
        ->where('xp.rows.0.cells.8.module_xp', 50_000)
        ->where('xp.rows.0.cells.9.unlocks.xp', 225_000)
        ->where('xp.rows.0.cells.9.module_xp', 100_000)
        // The top of the line unlocks nothing further.
        ->where('xp.rows.0.cells.10.unlocks', null)
        ->where('xp.rows.0.cells.10.module_xp', 160_000)
        ->where('xp.rows.0.xp_remaining', 684_400)
        ->where('xp.xp_remaining', 684_400)
        ->where('totals.xp_remaining', 684_400),
    );
});

/**
 * An unlock is settled once the vehicle it leads to is researched, and that has
 * to be the same judgement the purchase board makes or one tab would call a
 * tank bought while the other still charged for researching it.
 */
it('settles an unlock once the next tank is researched', function () {
    $user = User::factory()->create();
    freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_unlocked' => true])->assertRedirect();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.rows.0.cells.8.unlocks.is_unlocked', true)
        // The unlock drops out; the tier VIII's own modules do not.
        ->where('xp.rows.0.xp_remaining', 535_000),
    );
});

/**
 * Play history settles everything below it, the same back-fill the purchase
 * board uses — a tier IX researched past and then sold still counts.
 */
it('settles the unlocks below a vehicle that has been played', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);
    played($account, 100);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.rows.0.cells.8.unlocks.is_unlocked', true)
        ->where('xp.rows.0.cells.9.unlocks.is_unlocked', true)
        // Only module XP is left across the whole line.
        ->where('xp.rows.0.xp_remaining', 310_000),
    );
});

it('records a blueprint discount and gives it back', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.research-xp', 90), ['research_xp' => 60_000])
        ->assertRedirect();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.rows.0.cells.8.unlocks.xp', 60_000)
        ->where('xp.rows.0.cells.8.unlocks.full_xp', 149_400)
        ->where('xp.rows.0.cells.8.unlocks.is_discounted', true)
        ->where('xp.rows.0.xp_remaining', 595_000),
    );

    // Null, not zero: clearing restores the encyclopedia figure, where zero
    // would record a tank you can unlock outright.
    $this->actingAs($user)->patch(route('wot.grinding.research-xp', 90), ['research_xp' => null]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.rows.0.cells.8.unlocks.xp', 149_400)
        ->where('xp.rows.0.cells.8.unlocks.is_discounted', false),
    );

    expect(WotTankPurchase::where('wot_account_id', $account->id)->where('tank_id', 90)->first()->research_xp)
        ->toBeNull();
});

it('keeps a recorded zero apart from no discount at all', function () {
    $user = User::factory()->create();
    freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.research-xp', 90), ['research_xp' => 0]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.rows.0.cells.8.unlocks.xp', 0)
        // Fragments enough to unlock outright is a discount, not an absence.
        ->where('xp.rows.0.cells.8.unlocks.is_discounted', true),
    );
});

it('drops module XP as modules are ticked researched', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.research-module', 90), [
        'module_id' => 200, 'researched' => true,
    ])->assertRedirect();

    expect(WotTankModule::where('wot_account_id', $account->id)->first()->researched_module_ids)->toBe([200]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('xp.rows.0.cells.9.module_xp', 10_000)
        ->where('xp.rows.0.cells.9.module_xp_total', 100_000),
    );
});

/**
 * A researched module has nothing left to spend Free XP on, so it comes off the
 * plan — otherwise the Free XP board would keep charging for it.
 */
it('takes a researched module off the Free XP plan', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 200, 'planned' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.research-module', 90), ['module_id' => 200, 'researched' => true]);

    $row = WotTankModule::where('wot_account_id', $account->id)->first();

    expect($row->planned_module_ids)->toBe([])
        ->and($row->researched_module_ids)->toBe([200]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(
        fn ($page) => $page->where('totals.free_xp_planned', 0),
    );
});

it('refuses to plan Free XP for a module already researched', function () {
    $user = User::factory()->create();
    $account = freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.research-module', 90), ['module_id' => 200, 'researched' => true]);
    $this->actingAs($user)->patch(route('wot.grinding.module-plan', 90), ['module_id' => 200, 'planned' => true]);

    expect(WotTankModule::where('wot_account_id', $account->id)->first()->planned_module_ids ?? [])->toBe([]);
});

it('counts a shared vehicle on one XP row only', function () {
    $user = User::factory()->create();
    freeXpLine($user);

    $nation = WotVehicle::where('tank_id', 100)->value('nation');
    WotVehicle::factory()->create(['tank_id' => 101, 'name' => 'Other X', 'short_name' => 'Oth X', 'tier' => 10, 'nation' => $nation, 'next_tanks' => null]);
    WotVehicle::where('tank_id', 90)->update(['next_tanks' => json_encode([100 => 225_000, 101 => 240_000])]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('xp.rows', 2)
        ->where('xp.rows.0.name', 'Oth X')
        ->where('xp.rows.0.cells.9.is_shared', false)
        // Each row's tier IX cell points at that row's own tier X.
        ->where('xp.rows.0.cells.9.unlocks.tank_id', 101)
        ->where('xp.rows.1.cells.9.is_shared', true)
        ->where('xp.rows.1.cells.9.shared_with', 'Oth X')
        /*
         * The tier IX is shared, but the two unlocks off it are not: this row
         * still owes the 225,000 to reach its own tier X, while its module XP
         * is the other row's to count.
         *
         * Row 0: 149,400 + 50,000 (T8) + 240,000 + 100,000 (T9) = 539,400
         * Row 1: 225,000 (T9's unlock only) + 160,000 (T10 modules) = 385,000
         */
        ->where('xp.rows.1.cells.9.unlocks.is_shared', false)
        ->where('xp.rows.1.cells.9.unlocks.xp', 225_000)
        ->where('xp.rows.0.xp_remaining', 539_400)
        ->where('xp.rows.1.xp_remaining', 385_000)
        ->where('xp.xp_remaining', 924_400),
    );
});

it('rejects a research figure the board could never produce', function (array $payload) {
    $user = User::factory()->create();
    freeXpLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.research-xp', 90), $payload)->assertSessionHasErrors();
})->with([
    'negative' => [['research_xp' => -1]],
    'absurd' => [['research_xp' => 99_999_999]],
    'missing' => [[]],
]);

it('will not let one account record another account research', function () {
    $owner = User::factory()->create();
    $account = freeXpLine($owner);

    $intruder = User::factory()->create();
    WotAccount::factory()->for($intruder)->create(['account_id' => 999_999]);

    $this->actingAs($intruder)->patch(route('wot.grinding.research-xp', 90), ['research_xp' => 1]);
    $this->actingAs($intruder)->patch(route('wot.grinding.research-module', 90), ['module_id' => 200, 'researched' => true]);

    expect(WotTankPurchase::where('wot_account_id', $account->id)->count())->toBe(0)
        ->and(WotTankModule::where('wot_account_id', $account->id)->count())->toBe(0);
});

it('remembers the XP filters under their own board key', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), [
        'board' => 'xp', 'hidden_tiers' => [1, 2], 'hide_done' => false,
    ])->assertNoContent();

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('settings.xp_filters.hidden_tiers', [1, 2])
        ->where('settings.xp_filters.hide_done', false)
        ->where('settings.purchase_filters', null),
    );
});
