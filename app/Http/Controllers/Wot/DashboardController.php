<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotArticle;
use App\Models\WotEvent;
use App\Services\Wargaming\AccountDashboard;
use App\Services\Wargaming\WargamingException;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, AccountDashboard $dashboard): Response
    {
        $account = $request->user()->wotAccount;

        // Nothing linked yet — the Vue side renders the connect screen instead
        // of an empty dashboard.
        if (! $account) {
            return Inertia::render('Connect');
        }

        try {
            $data = $dashboard->for($account);
        } catch (WargamingException $e) {
            // A spent token is recoverable by reconnecting, so say so rather
            // than showing a generic failure.
            if ($e->isInvalidAccessToken()) {
                $account->forgetToken();
            }

            // The panels come from our own tables, so they still render when
            // the Wargaming API is the thing that failed.
            return Inertia::render('Dashboard', [
                'account' => $this->accountProps($account),
                'error' => $e->getMessage(),
                'summary' => null,
                'vehicles' => [],
                ...$this->sidePanels($request),
            ]);
        }

        $account->update(['last_synced_at' => now()]);

        return Inertia::render('Dashboard', [
            'account' => $this->accountProps($account),
            'error' => null,
            ...$data,
            ...$this->sidePanels($request),
        ]);
    }

    /**
     * The two panels above the statistics: recent news, and what is happening
     * over the next few days.
     *
     * Both read local tables rather than any API, so they cost a few
     * milliseconds and survive Wargaming being unreachable.
     *
     * @return array<string, mixed>
     */
    private function sidePanels(Request $request): array
    {
        $user = $request->user();

        return [
            'news' => [
                // pinnedFirstFor supplies the pinned_at column the panel reads;
                // on the Latest tab it also hoists pinned articles, which is
                // what makes a pin visible without switching tabs.
                'latest' => $this->articles(
                    WotArticle::query()->withSeenFor($user)->pinnedFirstFor($user),
                    $user,
                ),
                // Both tabs are sent up front: five rows each is a trivial
                // payload, and switching tabs shouldn't cost a round trip.
                'pinned' => $this->articles(
                    WotArticle::query()->onlyPinnedBy($user)->withSeenFor($user)->pinnedFirstFor($user),
                    $user,
                ),
            ],
            'upcoming' => $this->upcoming(),
        ];
    }

    /**
     * @param  Builder<WotArticle>  $query
     * @return list<array<string, mixed>>
     */
    private function articles($query, ?User $user): array
    {
        return $query->limit(5)->get()->map(fn (WotArticle $article): array => [
            'id' => $article->id,
            'title' => $article->title,
            'url' => $article->url,
            'category' => $article->category,
            'image_url' => $article->image_url,
            'published_at' => $article->published_at->toIso8601String(),
            'is_seen' => $article->seen_at !== null,
            'is_pinned' => $article->pinned_at !== null,
        ])->all();
    }

    /**
     * Five day buckets, plus the long campaigns kept out of them.
     *
     * A campaign running for weeks would otherwise appear in all five days and
     * bury the handful of things actually scheduled — the same problem the
     * month calendar has, solved the same way so the two read consistently.
     *
     * @return array<string, mixed>
     */
    private function upcoming(): array
    {
        $from = Carbon::today();
        $to = $from->copy()->addDays(4)->endOfDay();

        $events = WotEvent::with('article:id,title,url')
            ->onlyBetween($from, $to)
            ->orderBy('starts_at')
            ->get();

        [$ongoing, $dated] = $events->partition(
            fn (WotEvent $event): bool => $event->ends_at !== null
                && $event->starts_at->diffInDays($event->ends_at) > 7,
        );

        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $days[] = [
                'date' => $date->toDateString(),
                'label' => $date->isToday() ? 'Today' : $date->format('D j M'),
                'is_today' => $date->isToday(),
                'events' => $dated
                    ->filter(fn (WotEvent $e): bool => $e->starts_at->lte($dayEnd) && ($e->ends_at ?? $e->starts_at)->gte($dayStart))
                    ->map(fn (WotEvent $e): array => [
                        'id' => $e->id,
                        'title' => $e->title,
                        'source' => $e->source,
                        'url' => $e->article?->url,
                        // Only a session that starts on this day gets a time; a
                        // multi-day window showing "16:00" on every square would
                        // be stating something untrue.
                        'time' => $e->source === WotEvent::SOURCE_CALENDAR && $e->starts_at->isSameDay($dayStart)
                            ? $e->starts_at->format('H:i')
                            : null,
                    ])->values()->all(),
            ];
        }

        return [
            'days' => $days,
            'ongoing' => $ongoing->map(fn (WotEvent $e): array => [
                'id' => $e->id,
                'title' => $e->title,
                'ends_at' => $e->ends_at?->toIso8601String(),
                'url' => $e->article?->url,
            ])->values()->all(),
        ];
    }

    /**
     * Drops the cached API payloads and returns to the dashboard, which then
     * re-fetches. Useful straight after a session, when the 30-minute cache
     * would otherwise still be showing pre-battle numbers.
     */
    public function refresh(Request $request, AccountDashboard $dashboard): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $dashboard->forget($account);

        return back(fallback: route('wot.dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    private function accountProps(WotAccount $account): array
    {
        return [
            'nickname' => $account->nickname,
            'account_id' => $account->account_id,
            'is_token_valid' => $account->is_token_valid,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
        ];
    }
}
