<?php

use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;
use App\Models\WotVehicleModule;
use App\Models\WotTankPurchase;

/** A three-tier line: T8 -> T9 -> T10. */
function techLine(): array
{
    $ten = WotVehicle::factory()->create(['tank_id' => 100, 'name' => 'Target X', 'tier' => 10, 'price_credit' => 6_100_000, 'next_tanks' => null]);
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

it('subtracts banked and planned free XP from what is left', function () {
    $step = new WotGrindStep([
        'research_xp' => 149_310, 'research_xp_remaining' => 149_310,
        'module_xp_remaining' => 0, 'banked_xp' => 83_305, 'free_xp_planned' => 0,
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
        ->where('totals.xp_remaining', 0),
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
    'absurd free xp' => [['free_xp_planned' => 999_999_999]],
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
        'banked_xp' => 40_000, 'free_xp_planned' => 10_000, 'is_active' => true]);
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
        // (149,400 + 30,000 - 50,000) + (225,000 + 20,000 - 5,000)
        ->where('totals.active.xp_remaining', 369_400)
        // 55,000 covered of 424,400 required.
        ->where('totals.active.progress', 13),
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

/** A three-step line already seeded as a target, for the purchase board. */
function purchaseLine(User $user): array
{
    [$eight, $nine, $ten] = techLine();
    $account = WotAccount::factory()->for($user)->create();
    $target = WotGrindTarget::factory()->for($account, 'account')->create(['tank_id' => $ten->tank_id]);

    foreach ([[80, 8, 0], [90, 9, 1], [100, 10, 2]] as [$tank, $tier, $position]) {
        WotGrindStep::create(['wot_grind_target_id' => $target->id, 'tank_id' => $tank,
            'tier' => $tier, 'position' => $position]);
    }

    return [$account, $target, $ten];
}

it('lays the purchase board out as one column per tier', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // Tier VIII is the vehicle being ground in and the only one at that
        // tier, so the whole column has been bought and does not render.
        ->where('purchase.tiers', [9, 10])
        ->has('purchase.rows', 1)
        // The cell is still in the payload, just not in a column.
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

it('drops a line once its last vehicle is bought', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 100), ['is_purchased' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->has('purchase.rows', 0)
        // Buying the top of a line settles it: you cannot research past a
        // vehicle you do not own, so the tiers below were bought too.
        ->where('totals.credits_required', 0),
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

/**
 * The sheet stopped at tier X. Tier XI is reached from a tier X's next_tanks
 * without becoming a grind step, so no XP figure moves.
 */
it('adds the tier XI above a target without touching the research path', function () {
    $user = User::factory()->create();
    [$account, $target, $ten] = purchaseLine($user);

    $eleven = WotVehicle::factory()->create(['tank_id' => 110, 'name' => 'Top XI', 'tier' => 11,
        'price_credit' => 7_400_000, 'next_tanks' => null]);
    $ten->update(['next_tanks' => [$eleven->tank_id => 325_000]]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.tiers', [9, 10, 11])
        ->where('purchase.rows.0.cells.11.name', 'Top XI')
        ->where('purchase.rows.0.credits_remaining', 16_900_000)
        // The grind path is untouched: still three steps, same XP.
        ->where('targets.0.steps', fn ($steps) => count($steps) === 3),
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

it('drops a tier column once every line has bought that tier', function () {
    $user = User::factory()->create();
    purchaseLine($user);

    $this->actingAs($user)->get(route('wot.grinding'))
        ->assertInertia(fn ($page) => $page->where('purchase.tiers', [9, 10]));

    $this->actingAs($user)->patch(route('wot.grinding.purchase', 90), ['is_purchased' => true]);

    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        ->where('purchase.tiers', [10])
        // Dropping the column must not drop the money: the tier X is still owed.
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

    $ten = WotVehicle::factory()->create(['tank_id' => 100, 'name' => 'Long X', 'tier' => 10,
        'nation' => 'ussr', 'price_credit' => 6_100_000, 'next_tanks' => null]);
    $otherTen = WotVehicle::factory()->create(['tank_id' => 101, 'name' => 'Short X', 'tier' => 10,
        'nation' => 'ussr', 'price_credit' => 6_100_000, 'next_tanks' => null]);
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

    // Same nation and tier, so rows come back in name order: Long X, Short X.
    $this->actingAs($user)->get(route('wot.grinding'))->assertInertia(fn ($page) => $page
        // Tier VII is owned on both lines and drops; tier VIII survives on the
        // strength of the long line alone.
        ->where('purchase.tiers', [8, 9, 10])
        ->where('purchase.rows.0.name', 'Long X')
        ->where('purchase.rows.0.cells.8.is_purchased', false)
        ->where('purchase.rows.1.name', 'Short X')
        // Never a step on this line; filled from the tree and read as bought.
        ->where('purchase.rows.1.cells.8.tank_id', 80)
        ->where('purchase.rows.1.cells.8.is_purchased', true)
        // Filled-in tiers are already paid for and add nothing to the bill.
        ->where('purchase.rows.1.credits_remaining', 6_100_000),
    );
});
