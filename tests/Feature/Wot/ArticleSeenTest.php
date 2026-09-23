<?php

use App\Models\User;
use App\Models\WotArticle;

it('requires auth', function () {
    $article = WotArticle::factory()->create();

    $this->post(route('wot.news.articles.mark-seen', $article))->assertRedirect(route('login'));
    $this->post(route('wot.news.articles.mark-all-seen'))->assertRedirect(route('login'));
});

it('reports every article as unseen to a new user', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(3)->create();

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 3)
        ->where('articles.data.0.is_seen', false),
    );
});

it('marks an article seen', function () {
    $user = User::factory()->create();
    $articles = WotArticle::factory()->count(3)->create();

    foreach ($articles->take(2) as $article) {
        $this->actingAs($user)
            ->post(route('wot.news.articles.mark-seen', $article))
            ->assertRedirect();
    }

    expect(WotArticle::onlySeenBy($user)->count())->toBe(2);

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 1),
    );
});

/**
 * The pointer can rest on the same card twice in a visit, so the same article
 * can be posted twice. The first sighting is the useful fact — re-seeing
 * something must not make it look freshly discovered.
 */
it('keeps the original timestamp when an article is seen again', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $article));
    $first = $user->seenArticles()->first()->pivot->seen_at;

    $this->travel(1)->hours();
    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $article));

    expect(WotArticle::onlySeenBy($user)->count())->toBe(1)
        ->and($user->seenArticles()->first()->pivot->seen_at)->toBe($first);
});

/**
 * Marking a second card must not disturb the first. Each call now carries one
 * article, so this is the per-article shape of what a mixed batch used to test
 * within a single request.
 */
it('leaves an already-seen article untouched when another is marked', function () {
    $user = User::factory()->create();
    $seen = WotArticle::factory()->create();
    $unseen = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $seen));
    $first = $user->seenArticles()->find($seen->id)->pivot->seen_at;

    $this->travel(1)->hours();
    $this->actingAs($user)
        ->post(route('wot.news.articles.mark-seen', $unseen))
        ->assertRedirect();

    expect(WotArticle::onlySeenBy($user)->count())->toBe(2)
        ->and($user->seenArticles()->find($seen->id)->pivot->seen_at)->toBe($first);
});

/**
 * Route model binding does the work the form request's rules used to: a client
 * holding an id that has since been deleted, or a malformed one, gets a 404
 * and writes nothing, rather than a validation error or a silent no-op.
 */
it('404s for an article id that resolves to nothing', function (mixed $id) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('wot.news.articles.mark-seen', $id))
        ->assertNotFound();

    expect(WotArticle::onlySeenBy($user)->count())->toBe(0);
})->with([
    'no such id' => [999999],
    'not an id at all' => ['nope'],
]);

/**
 * Both writes bind their constant columns, and in markAllSeen those bindings
 * sit in the `select` group while notSeenBy() adds one to `where`. A wrong
 * order would put one column's value into another and say nothing about it, so
 * these read the stored row back rather than counting it.
 */
it('stores the acting user and the current time on a single mark', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $article));

    $pivot = $user->seenArticles()->find($article->id)->pivot;

    expect($pivot->seen_at)->toBe(now()->toDateTimeString())
        ->and($pivot->user_id)->toBe($user->id)
        ->and($pivot->wot_article_id)->toBe($article->id);
});

it('stores the acting user and the current time on every row of a mark-all', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    WotArticle::factory()->count(3)->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-all-seen'));

    $seen = $user->seenArticles()->get();

    expect($seen)->toHaveCount(3);

    foreach ($seen as $article) {
        expect($article->pivot->seen_at)->toBe(now()->toDateTimeString())
            ->and($article->pivot->user_id)->toBe($user->id);
    }
});

/**
 * The race the single-statement write exists to close: another request has
 * already written a row for one of the articles. The old read-then-insert would
 * have collided with the unique index here; the conflict clause skips that row
 * and writes the rest, leaving the earlier sighting untouched.
 *
 * True concurrency isn't reproducible in this suite, so what is pinned is the
 * property that makes it safe — an existing row neither errors nor changes.
 */
it('writes around a row another request already wrote', function () {
    $user = User::factory()->create();
    $alreadySeen = WotArticle::factory()->create();
    WotArticle::factory()->count(2)->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $alreadySeen));
    $first = $user->seenArticles()->find($alreadySeen->id)->pivot->seen_at;

    $this->travel(1)->hours();
    $this->actingAs($user)
        ->post(route('wot.news.articles.mark-all-seen'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(WotArticle::onlySeenBy($user)->count())->toBe(3)
        ->and($user->seenArticles()->find($alreadySeen->id)->pivot->seen_at)->toBe($first);
});

it('marks everything seen at once', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(5)->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-all-seen'))->assertRedirect();

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 0)
        ->where('articles.data.0.is_seen', true),
    );
});

it('marking all again is harmless', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(3)->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-all-seen'));
    $this->actingAs($user)->post(route('wot.news.articles.mark-all-seen'));

    expect(WotArticle::onlySeenBy($user)->count())->toBe(3);
});

/**
 * The point of the feature: an article synced after the user caught up is new
 * again, without anything having been written for them when it arrived.
 */
it('treats a newly synced article as unseen', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(2)->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-all-seen'));
    WotArticle::factory()->create(['title' => 'Just published']);

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 1),
    );
});

it('keeps seen state private to each user', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    WotArticle::factory()->count(2)->create();

    $this->actingAs($mine)->post(route('wot.news.articles.mark-all-seen'));

    $this->actingAs($theirs)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 2),
    );
});

it('drops seen rows when the article is removed', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $article));
    $article->delete();

    expect(WotArticle::onlySeenBy($user)->count())->toBe(0);
});

it('reports seen and pinned state together', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.articles.pin', $article));
    $this->actingAs($user)->post(route('wot.news.articles.mark-seen', $article));

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('articles.data.0.is_pinned', true)
        ->where('articles.data.0.is_seen', true),
    );
});

/**
 * Clearing the backlog removes every NEW badge and the button itself, so a
 * banner would only restate what the page already shows.
 */
it('flashes no message when marking everything seen', function () {
    User::factory()->create();
    WotArticle::factory()->count(2)->create();

    $this->actingAs(User::first())
        ->post(route('wot.news.articles.mark-all-seen'))
        ->assertSessionMissing('success');
});
