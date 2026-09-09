<?php

use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotGrindSetting;
use App\Models\WotGrindStep;
use App\Models\WotGrindTarget;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;

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
