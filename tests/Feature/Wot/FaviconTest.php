<?php

use App\Models\User;

/**
 * The tank icon belongs to the sub-project, not the site. Both halves are
 * asserted together: the root /favicon.ico is fetched blind by browsers and
 * cannot be scoped to a path, so /wot declaring its own icons is the only
 * thing keeping the two apart.
 */
it('serves the tank icons on wot pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('wot.dashboard'))
        ->assertOk()
        ->assertSee('/images/wot/favicon/favicon.svg', escape: false)
        ->assertSee('/images/wot/favicon/favicon-32.png', escape: false)
        ->assertSee('/images/wot/favicon/apple-touch-icon.png', escape: false);
});

/**
 * The tab is named for the sub-project, not for the site: what the root view
 * prints is what shows until the page's own title arrives, and it must not
 * follow APP_NAME.
 */
it('names the tab WOT Hub whatever the app is called', function () {
    config(['app.name' => 'Something Else']);

    $this->actingAs(User::factory()->create())
        ->get(route('wot.dashboard'))
        ->assertSee('<title inertia>WOT Hub</title>', escape: false)
        ->assertDontSee('Something Else');
});

it('leaves the base site on the default icon', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('/images/wot/favicon/', escape: false);
});

it('has the icon files the markup points at', function (string $path) {
    expect(public_path($path))->toBeFile();
})->with([
    'images/wot/favicon/favicon.svg',
    'images/wot/favicon/favicon-32.png',
    'images/wot/favicon/apple-touch-icon.png',
]);
