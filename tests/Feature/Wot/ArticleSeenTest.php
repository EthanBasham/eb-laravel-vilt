<?php

use App\Models\User;
use App\Models\WotArticle;

it('requires auth', function () {
    $article = WotArticle::factory()->create();

    $this->post(route('wot.news.seen'), ['ids' => [$article->id]])->assertRedirect(route('login'));
    $this->post(route('wot.news.seen-all'))->assertRedirect(route('login'));
});

it('reports every article as unseen to a new user', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(3)->create();

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 3)
        ->where('articles.data.0.is_seen', false),
    );
});

it('marks a batch of articles seen', function () {
    $user = User::factory()->create();
    $articles = WotArticle::factory()->count(3)->create();

    $this->actingAs($user)
        ->post(route('wot.news.seen'), ['ids' => $articles->take(2)->pluck('id')->all()])
        ->assertRedirect();

    expect($user->seenArticles()->count())->toBe(2);

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 1),
    );
});

/**
 * The client batches whatever scrolled past, so the same id can arrive twice.
 * The first sighting is the useful fact — re-seeing something must not make it
 * look freshly discovered.
 */
it('keeps the original timestamp when an article is seen again', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.seen'), ['ids' => [$article->id]]);
    $first = $user->seenArticles()->first()->pivot->seen_at;

    $this->travel(1)->hours();
    $this->actingAs($user)->post(route('wot.news.seen'), ['ids' => [$article->id]]);

    expect($user->seenArticles()->count())->toBe(1)
        ->and($user->seenArticles()->first()->pivot->seen_at)->toBe($first);
});

it('ignores ids for articles that no longer exist', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)
        ->post(route('wot.news.seen'), ['ids' => [$article->id, 999999]])
        ->assertSessionHasNoErrors();

    expect($user->seenArticles()->count())->toBe(1);
});

it('validates the batch', function (mixed $ids) {
    $this->actingAs(User::factory()->create())
        ->post(route('wot.news.seen'), ['ids' => $ids])
        ->assertSessionHasErrors('ids');
})->with([
    'empty' => [[]],
    'not an array' => ['nope'],
    'too many' => [fn () => range(1, 101)],
]);

it('marks everything seen at once', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(5)->create();

    $this->actingAs($user)->post(route('wot.news.seen-all'))->assertSessionHas('success');

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 0)
        ->where('articles.data.0.is_seen', true),
    );
});

it('marking all again is harmless', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(3)->create();

    $this->actingAs($user)->post(route('wot.news.seen-all'));
    $this->actingAs($user)->post(route('wot.news.seen-all'));

    expect($user->seenArticles()->count())->toBe(3);
});

/**
 * The point of the feature: an article synced after the user caught up is new
 * again, without anything having been written for them when it arrived.
 */
it('treats a newly synced article as unseen', function () {
    $user = User::factory()->create();
    WotArticle::factory()->count(2)->create();

    $this->actingAs($user)->post(route('wot.news.seen-all'));
    WotArticle::factory()->create(['title' => 'Just published']);

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 1),
    );
});

it('keeps seen state private to each user', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    WotArticle::factory()->count(2)->create();

    $this->actingAs($mine)->post(route('wot.news.seen-all'));

    $this->actingAs($theirs)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('unseenCount', 2),
    );
});

it('drops seen rows when the article is removed', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.seen'), ['ids' => [$article->id]]);
    $article->delete();

    expect($user->seenArticles()->count())->toBe(0);
});

it('reports seen and pinned state together', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.pin', $article));
    $this->actingAs($user)->post(route('wot.news.seen'), ['ids' => [$article->id]]);

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('articles.data.0.is_pinned', true)
        ->where('articles.data.0.is_seen', true),
    );
});
