<?php

use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;
use App\Models\WotVehicleModule;

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
