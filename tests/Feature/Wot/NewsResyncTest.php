<?php

use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\WotArticle;

function newsResyncFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/{$name}"));
}

it('requires auth', function () {
    $this->post(route('wot.news.resync'))->assertRedirect(route('login'));
});

it('runs the sync command and reports success', function () {
    config(['wotnews.categories' => ['live-streams']]);

    Http::fake([
        '*/rss/news/*' => Http::response(newsResyncFixture('news.rss')),
        '*token-store-september-2026/' => Http::response(newsResyncFixture('calendar-article.html')),
        '*micro-patch-040926/' => Http::response(newsResyncFixture('plain-article.html')),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('wot.news.resync'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(WotArticle::count())->toBe(2);
});
