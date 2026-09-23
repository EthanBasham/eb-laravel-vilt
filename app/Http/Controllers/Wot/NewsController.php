<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Controller;
use App\Models\WotArticle;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $request->string('category')->toString() ?: null;
        $pinnedOnly = $request->boolean('pinned');
        $user = $request->user();

        return Inertia::render('News', [
            'articles' => fn () => WotArticle::query()
                ->when($category, fn ($query) => $query->where('wot_articles.category', $category))
                ->when($pinnedOnly, fn ($query) => $query->onlyPinnedBy($user))
                ->withCount('events')
                ->pinnedFirstFor($user)
                ->withSeenFor($user)
                ->paginate(24)
                ->withQueryString()
                ->through(fn (WotArticle $article): array => [
                    ...$article->card_entry,
                    'description' => $article->description,
                    'events_count' => $article->events_count,
                ]),
            'categories' => fn () => WotArticle::query()
                ->select('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->filter()
                ->values(),
            'activeCategory' => $category,
            'pinnedOnly' => $pinnedOnly,
            'pinnedCount' => fn () => WotArticle::onlyPinnedBy($user)->count(),
            'unseenCount' => fn () => WotArticle::notSeenBy($user)->count(),
        ]);
    }

    public function markSeen(Request $request, WotArticle $article): RedirectResponse
    {
        $article->markSeenBy($request->user());

        return back(fallback: route('wot.news.index'));
    }
    public function markAllSeen(Request $request): RedirectResponse
    {
        WotArticle::markAllSeenBy($request->user());

        return back(fallback: route('wot.news.index'));
    }

    public function pin(Request $request, WotArticle $article): RedirectResponse
    {
        $article->pinBy($request->user());

        return back(fallback: route('wot.news.index'));
    }
    public function unpin(Request $request, WotArticle $article): RedirectResponse
    {
        $article->unpinBy($request->user());

        return back(fallback: route('wot.news.index'));
    }
    
    public function resync(): RedirectResponse
    {
        // The command fetches up to a dozen-plus article bodies with a deliberate pace between requests, so this can take a while
        set_time_limit(0);

        Artisan::call('wot:sync-news');

        return back(fallback: route('wot.news.index'))->with('success', 'News and calendar resynced.');
    }
}
