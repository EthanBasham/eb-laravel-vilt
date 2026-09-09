<?php

namespace App\Services\WotNews;

use Illuminate\Support\Carbon;
use App\Models\WotEvent;

/**
 * Pulls dated events out of an article's HTML.
 *
 * Only structurally-marked dates are read. Plenty of articles describe an event
 * purely in prose — "the event runs from 8 to 15 September" — and extracting
 * those means either brittle patterns over free text or a language model. A
 * calendar that is silently *wrong* is worse than one that is merely sparse, so
 * anything not explicitly marked up is left alone.
 *
 * Two tiers, in order of preference:
 *
 *   calendar  The site's own event-calendar component. Each day is an
 *             <article data-date> with a title and exact UTC start/end times.
 *             Rare — roughly one article in twenty — but precise.
 *   window    A pair of data-timestamp attributes bracketing the whole article.
 *             About a quarter of articles. One coarse event, no per-day detail.
 *
 * When a calendar exists the window is skipped: the individual sessions are
 * strictly better information, and emitting both would put a duplicate
 * month-long bar behind every session.
 */
class EventExtractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public function extract(string $html, string $fallbackTitle): array
    {
        $xpath = $this->xpath($html);

        $calendar = $this->calendarDays($xpath);

        if ($calendar !== []) {
            return $calendar;
        }

        return $this->window($xpath, $fallbackTitle);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function calendarDays(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//div[contains(@class, "event-calendar")]//article[@data-date]');

        if (! $nodes || $nodes->length === 0) {
            return [];
        }

        $events = [];

        foreach ($nodes as $node) {
            $date = $node->getAttribute('data-date');

            if ($date === '') {
                continue;
            }

            $heading = $xpath->query('.//h3', $node)->item(0);
            $title = $heading ? $this->clean($heading->textContent) : "Event on {$date}";

            [$startsAt, $endsAt] = $this->times($xpath, $node, $date);

            if (! $startsAt) {
                continue;
            }

            $events[] = [
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'event_type' => $node->getAttribute('data-accent') ?: null,
                'source' => WotEvent::SOURCE_CALENDAR,
                'metadata' => array_filter([
                    'tokens' => $node->getAttribute('data-tokens') ?: null,
                    'icon' => $node->getAttribute('data-icon') ?: null,
                    'rewards' => $this->rewards($xpath, $node),
                ]),
            ];
        }

        return $events;
    }

    /**
     * The exact start and end for one calendar day.
     *
     * The markup is a run of .local-date-ctw / .local-time-ctw spans reading
     * "<date> at <time> – <date> at <time> (UTC)". Taken positionally: the
     * first date/time pair is the start, the second the end. Times are UTC, as
     * the trailing code says — the spans are what the site's own JavaScript
     * rewrites into the viewer's timezone, so the served values are the
     * canonical ones.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function times(\DOMXPath $xpath, \DOMNode $node, string $fallbackDate): array
    {
        $dates = [];
        $times = [];

        foreach ($xpath->query('.//*[contains(@class, "local-date-ctw")]', $node) as $span) {
            $dates[] = $this->clean($span->textContent);
        }

        foreach ($xpath->query('.//*[contains(@class, "local-time-ctw")]', $node) as $span) {
            $times[] = $this->clean($span->textContent);
        }

        $start = $this->combine($dates[0] ?? $fallbackDate, $times[0] ?? null);
        $end = $this->combine($dates[1] ?? $dates[0] ?? $fallbackDate, $times[1] ?? null);

        // A day with no parseable time still belongs on the calendar; it just
        // sits at midnight UTC rather than being dropped.
        $start ??= $this->combine($fallbackDate, null);

        return [$start, $end && $start && $end->gt($start) ? $end : null];
    }

    /**
     * A pair of data-timestamp attributes bracketing the article.
     *
     * Unix timestamps, so no timezone guessing. Fewer than two means there is
     * no interval to describe.
     *
     * @return list<array<string, mixed>>
     */
    private function window(\DOMXPath $xpath, string $title): array
    {
        $stamps = [];

        foreach ($xpath->query('//*[@data-timestamp]') as $node) {
            $value = (int) $node->getAttribute('data-timestamp');

            if ($value > 0) {
                $stamps[] = $value;
            }
        }

        $stamps = array_values(array_unique($stamps));

        if (count($stamps) < 2) {
            return [];
        }

        return [[
            'title' => $title,
            'starts_at' => Carbon::createFromTimestampUTC(min($stamps)),
            'ends_at' => Carbon::createFromTimestampUTC(max($stamps)),
            'event_type' => null,
            'source' => WotEvent::SOURCE_WINDOW,
            'metadata' => null,
        ]];
    }

    /**
     * Reward vehicles named on a calendar day, for display alongside it.
     *
     * @return list<string>
     */
    private function rewards(\DOMXPath $xpath, \DOMNode $node): array
    {
        $rewards = [];

        foreach ($xpath->query('.//li', $node) as $item) {
            $text = $this->clean($item->textContent);

            if ($text !== '') {
                $rewards[] = $text;
            }
        }

        return array_slice($rewards, 0, 8);
    }

    private function combine(?string $date, ?string $time): ?Carbon
    {
        if (! $date) {
            return null;
        }

        return rescue(
            fn (): Carbon => Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.($time ?: '00:00:00'), 'UTC'),
            null,
            report: false,
        );
    }
    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;

        // The site emits attributes with no separating whitespace
        // (data-accent="stream"data-date="…"), which libxml warns about but
        // parses correctly. Errors are suppressed rather than fixed upstream.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
