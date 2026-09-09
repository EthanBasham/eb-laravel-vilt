<?php

use App\Models\User;
use App\Models\WotArticle;

it('requires auth to pin', function () {
    $article = WotArticle::factory()->create();

    $this->post(route('wot.news.pin', $article))->assertRedirect(route('login'));
});

it('pins an article and hoists it above newer ones', function () {
    $user = User::factory()->create();
    $newest = WotArticle::factory()->create(['title' => 'Newest', 'published_at' => now()]);
    $older = WotArticle::factory()->create(['title' => 'Older', 'published_at' => now()->subWeek()]);

    $this->actingAs($user)->post(route('wot.news.pin', $older))->assertSessionHas('success');

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        // The older article now leads, purely because it is pinned.
        ->where('articles.data.0.title', 'Older')
        ->where('articles.data.0.is_pinned', true)
        ->where('articles.data.1.title', 'Newest')
        ->where('articles.data.1.is_pinned', false)
        ->where('pinnedCount', 1),
    );
});

it('orders several pins with the most recently pinned first', function () {
    $user = User::factory()->create();
    $first = WotArticle::factory()->create(['title' => 'Pinned first']);
    $second = WotArticle::factory()->create(['title' => 'Pinned second']);

    $this->actingAs($user)->post(route('wot.news.pin', $first));
    $this->travel(1)->minutes();
    $this->actingAs($user)->post(route('wot.news.pin', $second));

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('articles.data.0.title', 'Pinned second')
        ->where('articles.data.1.title', 'Pinned first'),
    );
});

it('unpins', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.pin', $article));
    $this->actingAs($user)->delete(route('wot.news.unpin', $article))->assertSessionHas('success');

    expect($user->pinnedArticles()->count())->toBe(0);
});

/**
 * Pinning twice must not violate the unique constraint, and must refresh the
 * position rather than silently leaving it where it was.
 */
it('re-pinning is idempotent and moves the article back to the top', function () {
    $user = User::factory()->create();
    $first = WotArticle::factory()->create(['title' => 'First']);
    $second = WotArticle::factory()->create(['title' => 'Second']);

    $this->actingAs($user)->post(route('wot.news.pin', $first));
    $this->travel(1)->minutes();
    $this->actingAs($user)->post(route('wot.news.pin', $second));
    $this->travel(1)->minutes();
    $this->actingAs($user)->post(route('wot.news.pin', $first))->assertSessionHas('success');

    expect($user->pinnedArticles()->count())->toBe(2);

    $this->actingAs($user)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('articles.data.0.title', 'First'),
    );
});

/**
 * Articles are shared rows synced from Wargaming's feed. One person pinning one
 * must not rearrange anybody else's feed — which is why pins are a per-user
 * table rather than a flag on the article.
 */
it('keeps pins private to the user who made them', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $article = WotArticle::factory()->create(['published_at' => now()->subWeek()]);
    WotArticle::factory()->create(['title' => 'Newer', 'published_at' => now()]);

    $this->actingAs($mine)->post(route('wot.news.pin', $article));

    $this->actingAs($theirs)->get(route('wot.news.index'))->assertInertia(fn ($page) => $page
        ->where('articles.data.0.title', 'Newer')
        ->where('articles.data.0.is_pinned', false)
        ->where('pinnedCount', 0),
    );
});

it('filters to pinned only', function () {
    $user = User::factory()->create();
    $pinned = WotArticle::factory()->create(['title' => 'Kept']);
    WotArticle::factory()->create(['title' => 'Ignored']);

    $this->actingAs($user)->post(route('wot.news.pin', $pinned));

    $this->actingAs($user)->get(route('wot.news.index', ['pinned' => 1]))->assertInertia(fn ($page) => $page
        ->has('articles.data', 1)
        ->where('articles.data.0.title', 'Kept')
        ->where('pinnedOnly', true),
    );
});

it('combines a category filter with pinned-first ordering', function () {
    $user = User::factory()->create();
    $pinned = WotArticle::factory()->create(['category' => 'Updates', 'title' => 'Pinned update', 'published_at' => now()->subWeek()]);
    WotArticle::factory()->create(['category' => 'Updates', 'title' => 'Newer update', 'published_at' => now()]);
    WotArticle::factory()->create(['category' => 'Specials', 'title' => 'A special', 'published_at' => now()]);

    $this->actingAs($user)->post(route('wot.news.pin', $pinned));

    $this->actingAs($user)->get(route('wot.news.index', ['category' => 'Updates']))->assertInertia(fn ($page) => $page
        ->has('articles.data', 2)
        ->where('articles.data.0.title', 'Pinned update'),
    );
});

it('drops pins when the article is removed', function () {
    $user = User::factory()->create();
    $article = WotArticle::factory()->create();

    $this->actingAs($user)->post(route('wot.news.pin', $article));
    $article->delete();

    expect($user->pinnedArticles()->count())->toBe(0);
});

/**
 * Many articles share a publish date. Without a deterministic final sort the
 * database may return tied rows in a different order per query, which across a
 * paginated set can show one article twice and another not at all.
 */
it('orders tied publish dates deterministically across pages', function () {
    $user = User::factory()->create();
    $sameMoment = now()->subDay();
    WotArticle::factory()->count(30)->create(['published_at' => $sameMoment]);

    $seen = collect();

    foreach ([1, 2] as $page) {
        $response = $this->actingAs($user)->get(route('wot.news.index', ['page' => $page]));
        $seen = $seen->merge(collect($response->viewData('page')['props']['articles']['data'])->pluck('id'));
    }

    expect($seen)->toHaveCount(30)
        ->and($seen->unique())->toHaveCount(30);
});
