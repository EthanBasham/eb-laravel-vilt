<?php

use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotCrewGuide;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A signed-in user with a linked account, and a guide of theirs.
 *
 * @return array{0: User, 1: WotCrewGuide}
 */
function guideOwner(): array
{
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    return [$user, WotCrewGuide::factory()->for($account, 'account')->create(['name' => 'Heavy brawler'])];
}

it('adds a guide to the account', function () {
    $user = User::factory()->create();
    $account = WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->post(route('wot.crews.guides.store'), ['name' => 'Light scout'])->assertRedirect();

    expect($account->crewGuides()->sole()->name)->toBe('Light scout');
});

it('refuses a guide with no name', function () {
    $user = User::factory()->create();
    WotAccount::factory()->for($user)->create();

    $this->actingAs($user)->post(route('wot.crews.guides.store'), ['name' => ''])->assertSessionHasErrors('name');

    expect(WotCrewGuide::count())->toBe(0);
});

it('renames a guide', function () {
    [$user, $guide] = guideOwner();

    $this->actingAs($user)->patch(route('wot.crews.guides.update', $guide), ['name' => 'Heavy sidescraper']);

    expect($guide->fresh()->name)->toBe('Heavy sidescraper');
});

it('removes a guide and what its roles held', function () {
    [$user, $guide] = guideOwner();
    $guide->saveRole('commander', ['included' => ['commander_sixthSense']]);

    $this->actingAs($user)->delete(route('wot.crews.guides.destroy', $guide));

    $this->assertDatabaseCount('wot_crew_guides', 0);
    $this->assertDatabaseCount('wot_crew_guide_roles', 0);
});

/**
 * The order is what a guide is for: it says what to train first.
 */
it('keeps a role\'s perks in the order they were sent', function () {
    [$user, $guide] = guideOwner();

    $this->actingAs($user)->put(route('wot.crews.guides.role', [$guide, 'commander']), [
        'included' => ['commander_sixthSense', 'brotherhood', 'repair'],
    ])->assertSessionHasNoErrors();

    expect($guide->roles()->sole())
        ->role->toBe('commander')
        ->included->toBe(['commander_sixthSense', 'brotherhood', 'repair']);
});

/**
 * Dragging the last perk back out sends an empty list, which `required` would
 * refuse.
 */
it('accepts a role emptied of every perk', function () {
    [$user, $guide] = guideOwner();
    $guide->saveRole('gunner', ['included' => ['gunner_sniper']]);

    $this->actingAs($user)->put(route('wot.crews.guides.role', [$guide, 'gunner']), ['included' => []])
        ->assertSessionHasNoErrors();

    expect($guide->roles()->sole()->included)->toBe([]);
});

it('refuses a perk the role cannot train', function () {
    [$user, $guide] = guideOwner();

    $this->actingAs($user)->put(route('wot.crews.guides.role', [$guide, 'loader']), [
        'included' => ['loader_pedant', 'commander_sixthSense'],
    ])->assertSessionHasErrors('included.1');

    expect($guide->roles()->count())->toBe(0);
});

it('refuses the same perk listed twice', function () {
    [$user, $guide] = guideOwner();

    $this->actingAs($user)->put(route('wot.crews.guides.role', [$guide, 'driver']), [
        'included' => ['repair', 'repair'],
    ])->assertSessionHasErrors('included.0');
});

/**
 * The note and the perks are saved separately, each as its control is left, so
 * neither write may clear the other.
 */
it('saves a note without disturbing the perks, and perks without disturbing the note', function () {
    [$user, $guide] = guideOwner();
    $role = route('wot.crews.guides.role', [$guide, 'driver']);

    $this->actingAs($user)->put($role, ['included' => ['driver_virtuoso']]);
    $this->actingAs($user)->put($role, ['notes' => 'Repairs first on anything tracked often']);
    $this->actingAs($user)->put($role, ['included' => ['repair', 'driver_virtuoso']]);

    expect($guide->roles()->sole())
        ->included->toBe(['repair', 'driver_virtuoso'])
        ->notes->toBe('Repairs first on anything tracked often');
});

it('saves a note for a role with no perks chosen yet', function () {
    [$user, $guide] = guideOwner();

    $this->actingAs($user)->put(route('wot.crews.guides.role', [$guide, 'radioman']), ['notes' => 'Undecided'])
        ->assertSessionHasNoErrors();

    expect($guide->roles()->sole())->included->toBe([])->notes->toBe('Undecided');
});

it('has no route for a role that does not exist', function () {
    [$user, $guide] = guideOwner();

    $this->actingAs($user)->put("/wot/crews/guides/{$guide->id}/roles/cook", ['included' => []])->assertNotFound();
});

it('hides another account\'s guide from every write', function (string $method, string $route, array $parameters) {
    [, $guide] = guideOwner();
    $stranger = User::factory()->create();
    WotAccount::factory()->for($stranger)->create();

    $this->actingAs($stranger)
        ->{$method}(route($route, [$guide, ...$parameters]), ['name' => 'Mine now', 'included' => ['repair']])
        ->assertNotFound();

    expect($guide->fresh())->name->toBe('Heavy brawler')
        ->and($guide->roles()->count())->toBe(0);
})->with([
    'rename' => ['patch', 'wot.crews.guides.update', []],
    'delete' => ['delete', 'wot.crews.guides.destroy', []],
    'role' => ['put', 'wot.crews.guides.role', ['commander']],
]);

it('sends a guest to sign in', function () {
    $this->post(route('wot.crews.guides.store'), ['name' => 'Light scout'])->assertRedirect(route('login'));
});

/**
 * Every role is answered for, saved or not, and the page is handed what each
 * can train so it can work out the bucket of everything left.
 */
it('reports what each role includes, with the perks to draw the rest from', function () {
    [$user, $guide] = guideOwner();
    $guide->saveRole('commander', ['included' => ['commander_sixthSense', 'repair'], 'notes' => 'Sixth Sense always']);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn (Assert $page) => $page
        ->component('Crews')
        ->has('guides', 1)
        ->where('guides.0.name', 'Heavy brawler')
        ->where('guides.0.roles.commander.included', ['commander_sixthSense', 'repair'])
        ->where('guides.0.roles.commander.notes', 'Sixth Sense always')
        // A role nothing was saved for is still answered for: nothing included.
        ->where('guides.0.roles.loader.included', [])
        ->where('guides.0.roles.loader.notes', null)
        ->where('role_perks.loader', config('wargaming.crew_role_perks.loader'))
        ->where('perks.commander_sixthSense.name', 'Sixth Sense'));
});

/**
 * A perk taken away by a patch must not linger in a guide as something to
 * train.
 */
it('drops a saved perk the role can no longer train', function () {
    [$user, $guide] = guideOwner();
    $guide->saveRole('gunner', ['included' => ['gunner_retired', 'gunner_sniper']]);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn (Assert $page) => $page
        ->where('guides.0.roles.gunner.included', ['gunner_sniper']));
});

it('shows only the account\'s own guides', function () {
    [$user] = guideOwner();
    WotCrewGuide::factory()->create(['name' => 'Someone else\'s']);

    $this->actingAs($user)->get(route('wot.crews'))->assertInertia(fn (Assert $page) => $page
        ->has('guides', 1)
        ->where('guides.0.name', 'Heavy brawler'));
});
