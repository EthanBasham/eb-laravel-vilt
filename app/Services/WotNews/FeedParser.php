<?php

namespace App\Services\WotNews;

use Illuminate\Support\Carbon;

/**
 * Reads worldoftanks.com's RSS into plain arrays.
 *
 * RSS rather than the news index HTML on purpose: it is a published interface
 * meant to be consumed, so it survives site redesigns, and it carries a stable
 * guid per item.
 */
class FeedParser
{
    /**
     * @return list<array{guid: string, url: string, title: string, description: ?string, category: ?string, image_url: ?string, published_at: Carbon}>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($feed === false) {
            throw new NewsFetchException('The news feed could not be parsed as XML.');
        }

        $items = [];

        foreach ($feed->channel->item ?? [] as $item) {
            $link = trim((string) $item->link);

            if ($link === '') {
                continue;
            }

            $items[] = [
                // Falls back to the link: guid is optional in RSS, and an item
                // without one is still worth keeping.
                'guid' => trim((string) $item->guid) ?: $link,
                'url' => $link,
                'title' => trim((string) $item->title),
                'description' => $this->plainText((string) $item->description),
                'category' => trim((string) $item->category) ?: null,
                'image_url' => $this->enclosure($item),
                'published_at' => $this->date((string) $item->pubDate),
            ];
        }

        return $items;
    }

    /**
     * Feed descriptions are HTML fragments. Stored as text because they are
     * rendered as a summary line, and passing third-party HTML into a Vue page
     * would mean either trusting it or sanitising it.
     */
    private function plainText(string $html): ?string
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text !== '' ? $text : null;
    }

    private function enclosure(\SimpleXMLElement $item): ?string
    {
        $url = (string) ($item->enclosure['url'] ?? '');

        return $url !== '' ? $url : null;
    }
    private function date(string $value): Carbon
    {
        // A malformed or missing pubDate shouldn't lose the article; it just
        // sorts as "now" until the next sync corrects it.
        //
        // The conversion is not cosmetic. A feed pubDate carries its own offset
        // ("+0000"), so Carbon keeps that offset and Eloquent would persist a
        // UTC wall clock into a column the read side interprets as app time.
        // Normalising here keeps the stored value in the one timezone
        // everything is read back in. The instant is unchanged either way.
        return rescue(
            fn (): Carbon => Carbon::parse($value)->setTimezone(config('app.timezone')),
            fn (): Carbon => Carbon::now(),
            report: false,
        );
    }
}
