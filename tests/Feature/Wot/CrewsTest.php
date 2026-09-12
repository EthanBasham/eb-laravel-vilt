<?php

use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotBattlePassCrew;
use App\Models\WotCrewBook;
use App\Models\WotCrewMember;
use App\Models\WotCrewRecruit;
use App\Models\WotGrindSetting;
use App\Models\WotTankCrew;
use App\Models\WotVehicle;
use Inertia\Inertia;

/*
 * AccountProgress is not read by this page, but TechTree and TechTreeLines are
 * still singletons that memoise the tree for the life of the container — and a
 * test case shares one container across every request. So vehicles are created
 * before the first render, and writes go before the single get() that asserts
 * them, exactly as the grinding board's tests do.
 */

/** A three-tier line: T8 -> T9 -> T10, all with the usual five-seat crew. */
function crewLine(string $nation = 'ussr'): array
{
    $ten = WotVehicle::factory()->create(['tank_id' => 100, 'name' => 'Target X', 'short_name' => 'Tgt X', 'tier' => 10, 'nation' => $nation, 'type' => 'mediumTank', 'next_tanks' => null]);
    $nine = WotVehicle::factory()->create(['tank_id' => 90, 'name' => 'Mid IX', 'tier' => 9, 'nation' => $nation, 'next_tanks' => [100 => 225_000]]);
    $eight = WotVehicle::factory()->create(['tank_id' => 80, 'name' => 'Low VIII', 'tier' => 8, 'nation' => $nation, 'next_tanks' => [90 => 149_400]]);

    return [$eight, $nine, $ten];
}

/** @return array{0: User, 1: WotAccount} */
function crewUser(): array
{
    $user = User::factory()->create();

    return [$user, WotAccount::factory()->for($user)->create()];
}

/**
 * A crew on the tier X, with one entry per seat given as
 * [zero_skills, skill_level, is_max].
 */
function crewOn(WotAccount $account, array $seats, bool $balanced = false, int $tankId = 100): WotTankCrew
{
    $crew = WotTankCrew::create(['wot_account_id' => $account->id, 'tank_id' => $tankId, 'is_balanced' => $balanced]);

    foreach ($seats as $slot => [$zeroSkills, $level, $isMax]) {
        $crew->members()->create([
            'slot' => $slot,
            'zero_skills' => $zeroSkills,
            'skill_level' => $level,
            'is_max' => $isMax,
            'banked_xp' => 1_000,
        ]);
    }

    return $crew;
}

/** The tier X's cell, which every board assertion here is about. */
function tenCell(array $props): array
{
    return $props['crews']['rows'][0]['cells'][10];
}

it('keeps the board behind auth', function () {
    $this->get(route('wot.crews'))->assertRedirect(route('login'));
});

it('sends an unlinked user to the connect screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('wot.crews'))
        ->assertInertia(fn ($page) => $page->component('Connect'));
});

/**
 * The letters are the encyclopedia's, one per seat and in its order — the board
 * has no opinion about what crew a vehicle carries, only about who is in it.
 */
it('spells one letter per seat, in the vehicle own order', function () {
    [$user] = crewUser();
    crewLine();

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('crews.rows.0.cells.10.members.0.letter', 'C')
        ->where('crews.rows.0.cells.10.members.1.letter', 'G')
        ->where('crews.rows.0.cells.10.members.2.letter', 'D')
        ->where('crews.rows.0.cells.10.members.3.letter', 'R')
        ->where('crews.rows.0.cells.10.members.4.letter', 'L')
        ->where('crews.rows.0.cells.10.members.3.name', 'Radio Operator'),
    );
});

/**
 * One body, two jobs. The board spells a single letter for the seat — five
 * letters means five people to train — so the second role has to survive
 * somewhere, and that somewhere is the tooltip.
 */
it('names the other roles a doubled-up seat covers', function () {
    [$user] = crewUser();
    crewLine();
    WotVehicle::where('tank_id', 100)->first()->update([
        'crew' => WotVehicle::factory()->crew(['commander', 'gunner', 'driver', ['loader', 'radioman']])->raw()['crew'],
    ]);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('crews.rows.0.cells.10.members.3.letter', 'L')
        ->where('crews.rows.0.cells.10.members.3.also', ['Radio Operator'])
        // The seats before it cover one role each and say so with an empty list
        // rather than with a missing key.
        ->where('crews.rows.0.cells.10.members.0.also', []),
    );
});

it('reports no crew for a vehicle nothing has been said about', function () {
    [$user] = crewUser();
    crewLine();

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('crews.rows.0.cells.10.has_crew', false)
        ->where('crews.rows.0.cells.10.zero_state', 'none')
        // The seats are still spelled out: what is missing is the crew, not the
        // vehicle's need for one.
        ->has('crews.rows.0.cells.10.members', 5),
    );
});

/**
 * The four colours of a cell, which are the whole of what it says about
 * zero-skill crew. 'none' is the absence of a crew and is covered above.
 */
it('colours a cell by how much of the crew is zero-skill', function (array $seats, string $expected) {
    [$user, $account] = crewUser();
    crewLine();
    crewOn($account, $seats);

    $props = $this->actingAs($user)->get(route('wot.crews'))->viewData('page')['props'];

    expect(tenCell($props)['zero_state'])->toBe($expected);
})->with([
    'none of them' => [[[0, 3, false], [0, 3, false]], 'plain'],
    'some of them' => [[[1, 3, false], [0, 3, false]], 'mixed'],
    'every one' => [[[1, 3, false], [2, 4, false]], 'all'],
]);

/**
 * A member with two zeroed steps is no more zero-skill than one with a single
 * step — the count is what the asterisks report, not what the colour does.
 */
it('treats one zeroed step as zero-skill, like two', function () {
    [$user, $account] = crewUser();
    crewLine();
    crewOn($account, [[1, 2, false], [2, 2, false]]);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('crews.rows.0.cells.10.zero_state', 'all')
        ->where('crews.rows.0.cells.10.members.0.zero_skills', 1)
        ->where('crews.rows.0.cells.10.members.1.zero_skills', 2),
    );
});

it('calls a set maxed only when every member is', function () {
    [$user, $account] = crewUser();
    crewLine();
    crewOn($account, [[0, 6, true], [0, 6, false]]);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('crews.rows.0.cells.10.is_max', false)
        ->where('crews.rows.0.cells.10.members.0.is_max', true),
    );
});

it('totals banked XP across the members of a crew', function () {
    [$user, $account] = crewUser();
    crewLine();
    $crew = crewOn($account, [[0, 1, false], [0, 1, false]]);
    $crew->members()->where('slot', 0)->update(['banked_xp' => 250_000]);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        // 250,000 on the first seat and the helper's 1,000 on the second.
        ->where('crews.rows.0.cells.10.banked_xp', 251_000)
        ->where('crews.totals.banked_xp', 251_000),
    );
});

it('counts crews, maxed sets and zero-skill sets for the headline', function () {
    [$user, $account] = crewUser();
    crewLine();
    crewOn($account, [[1, 6, true], [1, 6, true]], tankId: 100);
    crewOn($account, [[0, 2, false], [0, 2, false]], tankId: 90);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('crews.totals.crews', 2)
        ->where('crews.totals.max_crews', 1)
        ->where('crews.totals.zero_skill_crews', 1),
    );
});

/**
 * A vehicle on two lines is crewed once, so the first row in display order
 * keeps the editor and the count, and the others carry it read-only. Without
 * this a tier VIII under three tier Xs would offer three editors over one crew
 * and count it three times.
 */
it('lets one row own a shared vehicle and counts it once', function () {
    [$user, $account] = crewUser();
    WotVehicle::factory()->create(['tank_id' => 100, 'short_name' => 'A X', 'tier' => 10, 'nation' => 'ussr', 'next_tanks' => null]);
    WotVehicle::factory()->create(['tank_id' => 101, 'short_name' => 'B X', 'tier' => 10, 'nation' => 'ussr', 'next_tanks' => null]);
    WotVehicle::factory()->create(['tank_id' => 90, 'short_name' => 'Shared IX', 'tier' => 9, 'nation' => 'ussr', 'next_tanks' => [100 => 225_000, 101 => 225_000]]);
    crewOn($account, [[1, 3, false]], tankId: 90);

    $props = $this->actingAs($user)->get(route('wot.crews'))->viewData('page')['props'];

    $rows = collect($props['crews']['rows']);

    expect($rows->pluck('cells.9.is_shared')->all())->toBe([false, true])
        ->and($rows->firstWhere('cells.9.is_shared', true)['cells'][9]['shared_with'])->toBe('A X')
        // Counted on the row that owns it, and nowhere else.
        ->and($rows->pluck('crews')->all())->toBe([1, 0])
        ->and($props['crews']['totals']['crews'])->toBe(1);
});

it('records a crew from the editor', function () {
    [$user, $account] = crewUser();
    crewLine();

    $this->actingAs($user)->put(route('wot.crews.tank', 100), [
        'is_balanced' => true,
        'members' => [
            ['slot' => 0, 'zero_skills' => 2, 'skill_level' => 5, 'is_max' => false, 'banked_xp' => 412_500],
            ['slot' => 1, 'zero_skills' => 0, 'skill_level' => 3, 'is_max' => true, 'banked_xp' => 0],
        ],
    ])->assertRedirect();

    $crew = WotTankCrew::where('wot_account_id', $account->id)->where('tank_id', 100)->first();

    expect($crew->is_balanced)->toBeTrue()
        ->and($crew->members)->toHaveCount(2)
        ->and($crew->members->first()->only(['slot', 'zero_skills', 'skill_level', 'is_max', 'banked_xp']))
        ->toBe(['slot' => 0, 'zero_skills' => 2, 'skill_level' => 5, 'is_max' => false, 'banked_xp' => 412_500]);
});

/**
 * The modal sends the whole set, so a seat left out of the payload is one that
 * has been emptied — the alternative is a member who cannot be removed once
 * they have been recorded.
 */
it('drops a seat left out of the saved crew', function () {
    [$user, $account] = crewUser();
    crewLine();
    crewOn($account, [[1, 1, false], [1, 1, false], [1, 1, false]]);

    $this->actingAs($user)->put(route('wot.crews.tank', 100), [
        'members' => [['slot' => 0, 'zero_skills' => 1, 'skill_level' => 1, 'is_max' => false, 'banked_xp' => 0]],
    ]);

    expect(WotTankCrew::where('tank_id', 100)->first()->members->pluck('slot')->all())->toBe([0]);
});

it('rejects a seat the vehicle does not have', function () {
    [$user] = crewUser();
    crewLine();
    WotVehicle::where('tank_id', 100)->first()->update([
        'crew' => WotVehicle::factory()->crew(['commander', 'gunner'])->raw()['crew'],
    ]);

    $this->actingAs($user)->put(route('wot.crews.tank', 100), [
        'members' => [['slot' => 2, 'zero_skills' => 0, 'skill_level' => 0, 'is_max' => false, 'banked_xp' => 0]],
    ])->assertStatus(422);

    expect(WotTankCrew::count())->toBe(0);
});

it('rejects a crew figure the editor could never produce', function (array $member) {
    [$user] = crewUser();
    crewLine();

    $this->actingAs($user)->put(route('wot.crews.tank', 100), [
        'members' => [['slot' => 0, 'zero_skills' => 0, 'skill_level' => 1, 'is_max' => false, 'banked_xp' => 0, ...$member]],
    ])->assertSessionHasErrors();
})->with([
    'a third zeroed step' => [['zero_skills' => 3]],
    'a seventh skill' => [['skill_level' => 7]],
    'a negative skill level' => [['skill_level' => -1]],
    'banked XP beyond the ceiling' => [['banked_xp' => 100_000_001]],
]);

it('rejects a crew for a tank the encyclopedia has never heard of', function () {
    [$user] = crewUser();
    crewLine();

    $this->actingAs($user)->put(route('wot.crews.tank', 4242), [
        'members' => [['slot' => 0, 'zero_skills' => 0, 'skill_level' => 0, 'is_max' => false, 'banked_xp' => 0]],
    ])->assertNotFound();
});

/**
 * No crew is the absence of a record rather than a crew of zeroes — a row full
 * of defaults would still paint the cell as a crew that happens to be
 * untrained, which is a different thing to report.
 */
it('empties a tank by deleting the crew and its members', function () {
    [$user, $account] = crewUser();
    crewLine();
    crewOn($account, [[1, 3, false], [1, 3, false]]);

    $this->actingAs($user)->delete(route('wot.crews.tank.destroy', 100))->assertRedirect();

    expect(WotTankCrew::count())->toBe(0)
        ->and(WotCrewMember::count())->toBe(0);
});

it('will not let one account empty another account crew', function () {
    [, $mine] = crewUser();
    [$other] = crewUser();
    crewLine();
    crewOn($mine, [[1, 3, false]]);

    $this->actingAs($other)->delete(route('wot.crews.tank.destroy', 100));

    expect(WotTankCrew::where('wot_account_id', $mine->id)->count())->toBe(1);
});

it('lists the crew XP progression from base to the sixth skill', function () {
    [$user] = crewUser();

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->has('xp_progression', 7)
        ->where('xp_progression.0.level', 0)
        ->where('xp_progression.0.label', 'Base — 100%')
        ->where('xp_progression.0.xp', 100_000)
        ->where('xp_progression.6.xp', 6_721_920),
    );
});

it('records how many recruits of a kind are held', function () {
    [$user, $account] = crewUser();

    $this->actingAs($user)->patch(route('wot.crews.recruit', 'boosted_3'), ['quantity' => 4])->assertRedirect();

    $props = $this->actingAs($user)->get(route('wot.crews'))->viewData('page')['props'];

    expect(WotCrewRecruit::where('wot_account_id', $account->id)->first()->quantity)->toBe(4)
        ->and(collect($props['recruits']['rows'])->firstWhere('key', 'boosted_3')['quantity'])->toBe(4)
        ->and($props['recruits']['total'])->toBe(4);
});

it('offers every kind of recruit, including the ones never edited', function () {
    [$user] = crewUser();

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->has('recruits.rows', 10)
        ->where('recruits.rows.0.key', 'zero_skill_commanders')
        ->where('recruits.rows.0.quantity', 0),
    );
});

it('refuses a kind of recruit that does not exist', function () {
    [$user] = crewUser();

    $this->actingAs($user)->patch(route('wot.crews.recruit', 'zero_skill_admirals'), ['quantity' => 1])
        ->assertNotFound();
});

it('records books against a nation and universally', function () {
    [$user, $account] = crewUser();

    $this->actingAs($user)->patch(route('wot.crews.book', ['manual', 'ussr']), ['quantity' => 3]);
    $this->actingAs($user)->patch(route('wot.crews.book', ['manual', 'universal']), ['quantity' => 2]);

    $props = $this->actingAs($user)->get(route('wot.crews'))->viewData('page')['props'];

    expect(WotCrewBook::where('wot_account_id', $account->id)->count())->toBe(2)
        ->and(collect($props['books']['rows'])->firstWhere('nation', 'ussr')['quantities']['manual'])->toBe(3)
        ->and(collect($props['books']['rows'])->firstWhere('nation', 'universal')['total'])->toBe(2)
        ->and($props['books']['totals']['manual'])->toBe(5);
});

it('gives every nation a row, with universal last', function () {
    [$user] = crewUser();

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        // The eleven nations of the tech tree, plus the stack that spends
        // anywhere.
        ->has('books.rows', 12)
        ->where('books.rows.0.nation', 'usa')
        ->where('books.rows.11.nation', 'universal'),
    );
});

/**
 * A Personal Training Manual is not a booklet, a guide or a manual, so adding
 * it to the bottom of those columns would make the total mean nothing.
 */
it('keeps the special items out of the book totals', function () {
    [$user] = crewUser();

    $this->actingAs($user)->patch(route('wot.crews.book', ['personal_training_manual', 'universal']), ['quantity' => 6]);
    $this->actingAs($user)->patch(route('wot.crews.book', ['guide', 'ussr']), ['quantity' => 1]);

    $props = $this->actingAs($user)->get(route('wot.crews'))->viewData('page')['props'];

    expect($props['books']['totals']['total'])->toBe(1)
        ->and(collect($props['books']['specials'])->firstWhere('key', 'personal_training_manual')['quantity'])->toBe(6)
        ->and(collect($props['books']['specials'])->pluck('key')->all())
        ->toBe(['personal_training_manual', 'mentoring_license']);
});

it('refuses a book cell that does not exist', function (array $cell) {
    [$user] = crewUser();

    $this->actingAs($user)->patch(route('wot.crews.book', $cell), ['quantity' => 1])->assertNotFound();
})->with([
    'an unknown book' => [['almanac', 'ussr']],
    'an unknown nation' => [['manual', 'atlantis']],
    // Neither special item is tied to a nation, so there is no national stack
    // of them to count.
    'a national special item' => [['mentoring_license', 'ussr']],
]);

it('adds a Battle Pass tanker to the roster', function () {
    [$user, $account] = crewUser();

    $this->actingAs($user)->post(route('wot.crews.battle-pass.store'), [
        'name' => 'Vasily Ivanovich',
        'nation' => 'ussr',
        'season' => 12,
        'gender' => 'male',
        'status' => 'in_barracks',
    ])->assertRedirect();

    expect(WotBattlePassCrew::where('wot_account_id', $account->id)->first())
        ->name->toBe('Vasily Ivanovich')
        ->season->toBe(12)
        ->status->toBe('in_barracks');
});

it('requires a name for a new tanker', function () {
    [$user] = crewUser();

    $this->actingAs($user)->post(route('wot.crews.battle-pass.store'), ['status' => 'in_barracks'])
        ->assertSessionHasErrors('name');

    expect(WotBattlePassCrew::count())->toBe(0);
});

/**
 * Where someone is serving only means anything while they are in a tank. A
 * tanker recalled to the barracks who still named a vehicle would show up as
 * that tank's crew anywhere this roster is read by tank.
 */
it('clears the posting when a tanker leaves the tank', function () {
    [$user, $account] = crewUser();
    crewLine();
    $crew = WotBattlePassCrew::create([
        'wot_account_id' => $account->id,
        'name' => 'Ellie',
        'status' => 'in_tank',
        'tank_id' => 100,
        'crew_role' => 'gunner',
    ]);

    $this->actingAs($user)->patch(route('wot.crews.battle-pass.update', $crew), ['status' => 'in_barracks']);

    expect($crew->fresh())->tank_id->toBeNull()->crew_role->toBeNull();
});

it('keeps the posting while the tanker is still in the tank', function () {
    [$user, $account] = crewUser();
    crewLine();
    $crew = WotBattlePassCrew::create([
        'wot_account_id' => $account->id,
        'name' => 'Ellie',
        'status' => 'in_tank',
        'tank_id' => 100,
        'crew_role' => 'gunner',
    ]);

    $this->actingAs($user)->patch(route('wot.crews.battle-pass.update', $crew), ['name' => 'Eleanor']);

    expect($crew->fresh())->name->toBe('Eleanor')->tank_id->toBe(100)->crew_role->toBe('gunner');
});

it('rejects a posting to a tank the encyclopedia has never heard of', function () {
    [$user, $account] = crewUser();
    $crew = WotBattlePassCrew::create(['wot_account_id' => $account->id, 'name' => 'Ellie', 'status' => 'in_tank']);

    $this->actingAs($user)->patch(route('wot.crews.battle-pass.update', $crew), ['tank_id' => 4242])
        ->assertSessionHasErrors('tank_id');
});

it('removes a tanker from the roster', function () {
    [$user, $account] = crewUser();
    $crew = WotBattlePassCrew::create(['wot_account_id' => $account->id, 'name' => 'Ellie', 'status' => 'uncollected']);

    $this->actingAs($user)->delete(route('wot.crews.battle-pass.destroy', $crew))->assertRedirect();

    expect(WotBattlePassCrew::count())->toBe(0);
});

/**
 * 404 rather than 403: another account's roster is not something this user
 * should be able to confirm the existence of.
 */
it('answers 404 for a tanker on another account roster', function () {
    [, $mine] = crewUser();
    [$other] = crewUser();
    $crew = WotBattlePassCrew::create(['wot_account_id' => $mine->id, 'name' => 'Ellie', 'status' => 'uncollected']);

    $this->actingAs($other)->patch(route('wot.crews.battle-pass.update', $crew), ['name' => 'Taken'])
        ->assertNotFound();

    expect($crew->fresh()->name)->toBe('Ellie');
});

it('lists the roster newest season first', function () {
    [$user, $account] = crewUser();
    WotBattlePassCrew::create(['wot_account_id' => $account->id, 'name' => 'Older', 'season' => 3, 'status' => 'uncollected']);
    WotBattlePassCrew::create(['wot_account_id' => $account->id, 'name' => 'Newer', 'season' => 14, 'status' => 'uncollected']);
    // No season yet, which sorts last rather than heading the list.
    WotBattlePassCrew::create(['wot_account_id' => $account->id, 'name' => 'Unknown', 'status' => 'uncollected']);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('battle_pass.0.name', 'Newer')
        ->where('battle_pass.1.name', 'Older')
        ->where('battle_pass.2.name', 'Unknown'),
    );
});

it('remembers where the crews filters were left', function () {
    [$user, $account] = crewUser();

    $this->actingAs($user)->patch(route('wot.crews.filters'), [
        'board' => 'crews',
        'hidden_nations' => ['japan'],
        'only_crewed' => true,
    ])->assertNoContent();

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn ($page) => $page
        ->where('settings.crews_filters.hidden_nations', ['japan'])
        ->where('settings.crews_filters.only_crewed', true),
    );
});

/**
 * The Crews board writes its filters to the same row every other board does, so
 * saving one must not disturb another.
 */
it('leaves the other boards filters alone when the crews board saves', function () {
    [$user, $account] = crewUser();

    $this->actingAs($user)->patch(route('wot.grinding.filters'), ['board' => 'purchase', 'hidden_tiers' => [1]]);
    $this->actingAs($user)->patch(route('wot.crews.filters'), ['board' => 'crews', 'hidden_tiers' => [2]]);

    $settings = WotGrindSetting::where('wot_account_id', $account->id)->first();

    expect($settings->purchase_filters)->toEqual(['hidden_tiers' => [1]])
        ->and($settings->crews_filters)->toEqual(['hidden_tiers' => [2]]);
});

/**
 * A thousand vehicles that only one of four tabs needs, so the page paints
 * without them and Inertia fetches them straight after. The picker offers every
 * vehicle rather than the board's lines: a tanker can be posted to a premium as
 * readily as to a tech-tree tank.
 */
it('defers the vehicle list the Battle Pass picker needs', function () {
    [$user] = crewUser();
    crewLine();
    WotVehicle::factory()->premium()->create(['tank_id' => 500, 'short_name' => 'Prem VIII', 'tier' => 8, 'nation' => 'ussr']);

    $this->actingAs($user)->get(route('wot.crews'))
        ->assertInertia(fn ($page) => $page->missing('vehicles'));

    // The version header is not optional on a partial visit: without it Inertia
    // answers 409 and asks the client to reload rather than serving the props.
    $vehicles = $this->actingAs($user)->get(route('wot.crews'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'Crews',
        'X-Inertia-Partial-Data' => 'vehicles',
    ])->json('props.vehicles');

    expect(collect($vehicles)->pluck('tank_id')->all())->toContain(500, 100);
});
