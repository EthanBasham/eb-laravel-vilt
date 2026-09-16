<?php

use App\Models\User;
use App\Models\WotBookmark;

/**
 * The bookmarks strip is shared from HandleInertiaRequests, so it reaches every
 * page in the group rather than being passed by each controller. Connect is the
 * one a brand-new account lands on, which makes it the page the bar most has to
 * be on.
 */
it('seeds the default bookmarks on a first visit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wot.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Connect')
            ->where('bookmarks', config('wotbookmarks.defaults')));

    expect($user->fresh()->wot_bookmarks_seeded_at)->not->toBeNull()
        ->and($user->bookmarks()->count())->toBe(count(config('wotbookmarks.defaults')));
});

/**
 * The whole reason `wot_bookmarks_seeded_at` exists. Without it an empty bar is
 * indistinguishable from a new account, and clearing the last bookmark would
 * hand all ten defaults straight back.
 */
it('leaves an emptied bar empty rather than reseeding it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('wot.bookmarks.update'), ['bookmarks' => []]);

    $this->actingAs($user)
        ->get(route('wot.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('bookmarks', []));

    expect($user->bookmarks()->count())->toBe(0);
});

it('replaces the whole list in the order it was posted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('wot.bookmarks.update'), ['bookmarks' => [
        ['label' => 'Second', 'url' => 'https://tanks.gg/', 'title' => null],
        ['label' => 'First', 'url' => 'https://tomato.gg/', 'title' => 'Stats'],
    ]])->assertRedirect();

    $this->actingAs($user)
        ->get(route('wot.dashboard'))
        ->assertInertia(fn ($page) => $page->where('bookmarks', [
            ['label' => 'Second', 'url' => 'https://tanks.gg/', 'title' => null],
            ['label' => 'First', 'url' => 'https://tomato.gg/', 'title' => 'Stats'],
        ]));

    // Replaced, not appended: the ten defaults this user was seeded with on the
    // first render are gone.
    expect($user->bookmarks()->count())->toBe(2);
});

/**
 * Nobody types a scheme into a bookmarks field, and `url` refuses a bare host,
 * so the common case would otherwise be a validation error on a good address.
 */
it('puts https on an address typed without a scheme', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('wot.bookmarks.update'), ['bookmarks' => [
        ['label' => 'Tanks.gg', 'url' => 'tanks.gg'],
    ]])->assertSessionHasNoErrors();

    expect($user->bookmarks()->sole()->url)->toBe('https://tanks.gg');
});

/**
 * `url` on its own passes javascript: and data:, which is stored XSS the moment
 * one is rendered into the href — and this href is rendered for the person who
 * typed it, which is exactly who a self-XSS is aimed at.
 */
it('refuses a bookmark that is not http or https', function (string $url) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('wot.bookmarks.update'), ['bookmarks' => [['label' => 'Bad', 'url' => $url]]])
        ->assertSessionHasErrors('bookmarks.0.url');

    expect($user->bookmarks()->count())->toBe(0);
})->with([
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'ftp://example.com',
]);

it('requires a label on every bookmark', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('wot.bookmarks.update'), ['bookmarks' => [['label' => '', 'url' => 'https://tanks.gg']]])
        ->assertSessionHasErrors('bookmarks.0.label');
});

it('writes only the signed-in user\'s bar', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    WotBookmark::seedDefaults($other);

    $this->actingAs($user)->put(route('wot.bookmarks.update'), ['bookmarks' => [
        ['label' => 'Mine', 'url' => 'https://tanks.gg'],
    ]]);

    expect($user->bookmarks()->count())->toBe(1)
        ->and($other->bookmarks()->count())->toBe(count(config('wotbookmarks.defaults')));
});

/**
 * The bar prints `label` and hangs the tooltip off `title`; a missing key would
 * render as an empty link rather than erroring. `url` is asserted absolute
 * because these leave the app — a relative one would quietly resolve against
 * /wot and 404.
 *
 * One test rather than a dataset: `config()` is unavailable while Pest collects
 * datasets, which happens before the application boots.
 */
it('gives every default bookmark a label, a tooltip and an external url', function () {
    foreach (config('wotbookmarks.defaults') as $bookmark) {
        expect($bookmark['label'])->toBeString()->not->toBeEmpty()
            ->and($bookmark['title'])->toBeString()->not->toBeEmpty()
            ->and($bookmark['url'])->toStartWith('https://');
    }
});
