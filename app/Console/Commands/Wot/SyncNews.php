<?php

namespace App\Console\Commands\Wot;

use Illuminate\Console\Command;
use App\Models\WotArticle;
use App\Models\WotEvent;
use App\Services\WotNews\EventExtractor;
use App\Services\WotNews\FeedParser;
use App\Services\WotNews\NewsClient;
use App\Services\WotNews\NewsFetchException;

/**
 * Pulls the news feeds, then fetches article bodies to extract event dates.
 *
 * Two stages on purpose. The feed is cheap, structured and safe to poll; the
 * bodies are one HTTP request each against someone else's server, so they are
 * fetched only for articles that are new or have been republished, and capped
 * per run.
 */
class SyncNews extends Command
{
    protected $signature = 'wot:sync-news {--bodies= : Override how many article bodies to fetch this run}';

    protected $description = 'Sync World of Tanks news articles and extract event dates';

    public function handle(NewsClient $client, FeedParser $parser, EventExtractor $extractor): int
    {
        $seen = 0;

        foreach (config('wotnews.categories') as $category) {
            try {
                $items = $parser->parse($client->feed($category));
            } catch (NewsFetchException $e) {
                $this->error("{$category}: {$e->getMessage()}");

                continue;
            }

            foreach ($items as $item) {
                WotArticle::updateOrCreate(
                    ['guid' => $item['guid']],
                    [
                        'url' => $item['url'],
                        'title' => $item['title'],
                        'description' => $item['description'],
                        // The feed's own category label is nicer than the slug,
                        // but fall back to the slug when it's absent.
                        'category' => $item['category'] ?? $category,
                        'image_url' => $item['image_url'],
                        'published_at' => $item['published_at'],
                    ],
                );
                $seen++;
            }

            $this->line("  {$category}: ".count($items).' items');
        }

        $this->info("{$seen} feed items processed.");

        return $this->extractEvents($client, $extractor);
    }
    private function extractEvents(NewsClient $client, EventExtractor $extractor): int
    {
        $limit = (int) ($this->option('bodies') ?? config('wotnews.bodies_per_sync'));

        $pending = WotArticle::query()
            ->where(fn ($query) => $query->whereNull('body_fetched_at')->orWhereColumn('published_at', '>', 'body_fetched_at'))
            ->inDefaultOrder()
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No article bodies need fetching.');

            return self::SUCCESS;
        }

        $this->info("Fetching {$pending->count()} article bodies...");
        $events = 0;

        foreach ($pending as $article) {
            try {
                $html = $client->article($article->url);
            } catch (NewsFetchException $e) {
                $this->warn("  {$article->title}: {$e->getMessage()}");

                continue;
            }

            // robots.txt disallows this path — recorded as fetched so it isn't
            // retried every run.
            if ($html === null) {
                $article->update(['body_fetched_at' => now(), 'body_hash' => null]);
                $this->line("  skipped (robots.txt): {$article->url}");

                continue;
            }

            $hash = hash('sha256', $html);

            // Unchanged since last time: nothing can have moved, so don't
            // churn the events table.
            if ($article->body_hash === $hash) {
                $article->update(['body_fetched_at' => now()]);

                continue;
            }

            $extracted = $extractor->extract($html, $article->title);
            $events += $this->store($article, $extracted);

            $article->update(['body_fetched_at' => now(), 'body_hash' => $hash]);

            $this->line('  '.count($extracted)." event(s): {$article->title}");

            // Deliberate pacing. Nothing here is urgent, and a burst of
            // requests at someone else's site is impolite regardless of
            // whether it would be noticed.
            usleep(700_000);
        }

        $this->info("{$events} event(s) stored.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $extracted
     */
    private function store(WotArticle $article, array $extracted): int
    {
        // Replaced rather than merged: an edited article may have moved or
        // removed dates, and a stale event nobody can trace back is worse than
        // re-inserting a few rows.
        $article->events()->delete();

        foreach ($extracted as $event) {
            WotEvent::create(['wot_article_id' => $article->id, ...$event]);
        }

        return count($extracted);
    }
}
