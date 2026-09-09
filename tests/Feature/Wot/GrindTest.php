<?php

use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotGrind;
use App\Models\WotVehicle;
use App\Models\WotVehicleSnapshot;

function chaffee(): WotVehicle
{
    return WotVehicle::factory()->create([
        'tank_id' => 9761,
        'name' => 'M24 Chaffee',
        'tier' => 5,
        'next_tanks' => [16673 => 28100],
        'modules_tree' => [
            'm1' => ['module_id' => 5001, 'name' => '75 mm Gun M17', 'type' => 'vehicleGun', 'price_xp' => 4100, 'is_default' => false],
            'm2' => ['module_id' => 5002, 'name' => 'Stock turret', 'type' => 'vehicleTurret', 'price_xp' => 0, 'is_default' => true],
        ],
    ]);
}

function fakeStats(int $accountId, int $tankId, int $xp, int $battles = 100, int $avgXp = 500): void
{
    Http::fake(['*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => [(string) $accountId => [
        ['tank_id' => $tankId, 'all' => ['xp' => $xp, 'battles' => $battles, 'battle_avg_xp' => $avgXp]],
    ]]])]);
}

it('keeps grinds behind auth', function () {
    $this->get(route('wot.grinds.index'))->assertRedirect(route('login'));
});

it('starts a grind from the vehicle current XP', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    WotVehicle::factory()->create(['tank_id' => 16673, 'name' => 'T37']);
    fakeStats(7, 9761, 681_987);

    $this->actingAs($user)
        ->post(route('wot.grinds.store'), ['tank_id' => 9761, 'target_type' => 'tank', 'target_id' => 16673])
        ->assertRedirect();

    $grind = $account->grinds()->first();

    expect($grind->target_name)->toBe('T37')
        ->and($grind->target_xp)->toBe(28100)
        // The baseline is where the vehicle stood when tracking began, not zero.
        ->and($grind->baseline_xp)->toBe(681_987)
        ->and($grind->is_complete)->toBeFalse();
});

it('measures progress forward from the baseline', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    WotGrind::factory()->for($account, 'account')->create([
        'tank_id' => 9761, 'target_id' => 16673, 'target_name' => 'T37',
        'target_xp' => 28100, 'baseline_xp' => 681_987,
    ]);
    // 7,000 XP earned since the grind started.
    fakeStats(7, 9761, 688_987, 110, 500);

    $this->actingAs($user)->get(route('wot.grinds.index'))->assertInertia(fn ($page) => $page
        ->component('Grinds')
        ->where('grinds.0.earned_xp', 7000)
        ->where('grinds.0.remaining_xp', 21100)
        ->where('grinds.0.progress', 24.9)
        // No snapshot history, so the rate falls back to the lifetime average
        // and says so rather than presenting it as a recent figure.
        ->where('grinds.0.rate_source', 'lifetime')
        ->where('grinds.0.xp_per_battle', 500)
        ->where('grinds.0.battles_remaining', 43)
        ->where('grinds.0.days_remaining', null),
    );
});

/**
 * A lifetime average says nothing about how often a vehicle is currently
 * played, so it must not be turned into a day estimate. Only an observed rate
 * from the snapshot history can do that.
 */
it('projects days only from observed recent play', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    WotGrind::factory()->for($account, 'account')->create([
        'tank_id' => 9761, 'target_id' => 16673, 'target_name' => 'T37',
        'target_xp' => 28100, 'baseline_xp' => 100_000,
    ]);

    // 10 days apart, 10,000 XP earned in between → 1,000 XP/day.
    WotVehicleSnapshot::create(['wot_account_id' => $account->id, 'tank_id' => 9761,
        'captured_at' => now()->subDays(10), 'battles' => 100, 'statistics' => ['xp' => 100_000, 'battles' => 100]]);
    WotVehicleSnapshot::create(['wot_account_id' => $account->id, 'tank_id' => 9761,
        'captured_at' => now(), 'battles' => 120, 'statistics' => ['xp' => 110_000, 'battles' => 120]]);

    fakeStats(7, 9761, 110_000, 120, 300);

    $this->actingAs($user)->get(route('wot.grinds.index'))->assertInertia(fn ($page) => $page
        ->where('grinds.0.earned_xp', 10_000)
        ->where('grinds.0.rate_source', 'recent')
        // 10,000 XP over 20 battles, not the 300 lifetime average.
        ->where('grinds.0.xp_per_battle', 500)
        // 18,100 remaining at 1,000/day.
        ->where('grinds.0.days_remaining', 18.1),
    );
});

it('completes a grind when the target is reached', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    $grind = WotGrind::factory()->for($account, 'account')->create([
        'tank_id' => 9761, 'target_id' => 16673, 'target_name' => 'T37',
        'target_xp' => 28100, 'baseline_xp' => 100_000,
    ]);
    fakeStats(7, 9761, 130_000);

    $this->actingAs($user)->get(route('wot.grinds.index'))->assertOk();

    expect($grind->fresh()->is_complete)->toBeTrue()
        ->and($grind->fresh()->completed_at)->not->toBeNull();
});

it('accepts a module target and rejects a default module', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    fakeStats(7, 9761, 500_000);

    $this->actingAs($user)->post(route('wot.grinds.store'), [
        'tank_id' => 9761, 'target_type' => 'module', 'target_id' => 5001,
    ])->assertRedirect();

    expect($account->grinds()->first()->target_name)->toBe('75 mm Gun M17');

    // Default modules come fitted; there is nothing to research.
    $this->actingAs($user)->post(route('wot.grinds.store'), [
        'tank_id' => 9761, 'target_type' => 'module', 'target_id' => 5002,
    ])->assertSessionHas('error');

    expect($account->grinds()->count())->toBe(1);
});

it('rejects a target that does not belong to the vehicle', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    fakeStats(7, 9761, 500_000);

    $this->actingAs($user)->post(route('wot.grinds.store'), [
        'tank_id' => 9761, 'target_type' => 'tank', 'target_id' => 99999,
    ])->assertSessionHas('error');

    expect($account->grinds()->count())->toBe(0);
});

it('re-declaring the same grind resets it rather than duplicating', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    WotVehicle::factory()->create(['tank_id' => 16673, 'name' => 'T37']);
    WotGrind::factory()->for($account, 'account')->completed()->create([
        'tank_id' => 9761, 'target_type' => 'tank', 'target_id' => 16673, 'baseline_xp' => 1,
    ]);
    fakeStats(7, 9761, 700_000);

    $this->actingAs($user)->post(route('wot.grinds.store'), [
        'tank_id' => 9761, 'target_type' => 'tank', 'target_id' => 16673,
    ]);

    expect($account->grinds()->count())->toBe(1)
        ->and($account->grinds()->first()->baseline_xp)->toBe(700_000)
        ->and($account->grinds()->first()->is_complete)->toBeFalse();
});

it('will not let one account delete another account grind', function () {
    $mine = User::factory()->create();
    WotAccount::factory()->for($mine)->create();
    $theirs = WotGrind::factory()->create();

    $this->actingAs($mine)->delete(route('wot.grinds.destroy', $theirs))->assertNotFound();

    expect(WotGrind::find($theirs->id))->not->toBeNull();
});

it('offers only researchable targets for owned vehicles', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create(['account_id' => 7]);
    chaffee();
    WotVehicle::factory()->create(['tank_id' => 16673, 'name' => 'T37']);
    // Owned but premium — no research line, so it must not be offered.
    WotVehicle::factory()->premium()->create(['tank_id' => 555, 'name' => 'Premium Thing', 'next_tanks' => null, 'modules_tree' => null]);

    Http::fake(['*/tanks/stats/*' => Http::response(['status' => 'ok', 'data' => ['7' => [
        ['tank_id' => 9761, 'all' => ['xp' => 1000, 'battles' => 10, 'battle_avg_xp' => 100]],
        ['tank_id' => 555, 'all' => ['xp' => 500, 'battles' => 5, 'battle_avg_xp' => 100]],
    ]]])]);

    $this->actingAs($user)->get(route('wot.grinds.index'))->assertInertia(fn ($page) => $page
        ->has('options', 1)
        ->where('options.0.tank_id', 9761)
        // The next tank plus the one non-default module.
        ->has('options.0.targets', 2),
    );
});
