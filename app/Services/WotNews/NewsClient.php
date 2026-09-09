<?php

namespace App\Services\WotNews;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches from worldoftanks.com — the RSS feed, and article HTML.
 *
 * Separate from WargamingClient: this is the public website, not the developer
 * API. No application id, no error envelope, different host, different rules.
 *
 * Two courtesies are deliberate rather than incidental. The User-Agent
 * identifies this application rather than impersonating a browser, so the
 * traffic is attributable. And robots.txt disallows two news subpaths, which
 * `mayFetch()` enforces here rather than trusting every caller to remember.
 */
class NewsClient
{
    private const BASE = 'https://worldoftanks.com';

    /** Paths robots.txt disallows for all user agents. */
    private const DISALLOWED = ['/news/wot-assistant/', '/news/wgc-client/'];

    public function __construct(private readonly ?string $baseUrl = null) {}

    /**
     * The site's RSS feed, either overall or for one category.
     */
    public function feed(?string $category = null): string
    {
        $path = $category ? "/en/rss/news/{$category}/" : '/en/rss/news/';

        return $this->get($path);
    }

    /**
     * One article's HTML, or null when robots.txt disallows it.
     */
    public function article(string $url): ?string
    {
        if (! $this->mayFetch($url)) {
            return null;
        }

        return $this->get($url);
    }

    /**
     * robots.txt disallows the "wot-assistant" and "wgc-client" news subpaths
     * for every user agent. Checked centrally so no caller can forget.
     */
    public function mayFetch(string $url): bool
    {
        foreach (self::DISALLOWED as $disallowed) {
            if (str_contains($url, $disallowed)) {
                return false;
            }
        }

        return true;
    }

    private function get(string $pathOrUrl): string
    {
        $url = str_starts_with($pathOrUrl, 'http')
            ? $pathOrUrl
            : ($this->baseUrl ?? self::BASE).$pathOrUrl;

        try {
            $response = Http::timeout(20)
                ->retry(2, 500, throw: false)
                ->withHeaders([
                    'User-Agent' => config('wotnews.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new NewsFetchException("Could not reach worldoftanks.com: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new NewsFetchException("worldoftanks.com returned HTTP {$response->status()} for {$url}.");
        }

        return $response->body();
    }
}
