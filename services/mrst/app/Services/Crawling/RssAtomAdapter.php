<?php

namespace App\Services\Crawling;

use App\Exceptions\FeedParseFailure;
use DOMDocument;
use DOMElement;

class RssAtomAdapter
{
    private const MAX_ITEMS = 100;

    /**
     * @return list<DiscoveredArticle>
     */
    public function parse(string $xml): array
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new FeedParseFailure('doctype_forbidden', 'Feeds that declare a DOCTYPE are rejected to prevent XXE.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new FeedParseFailure('malformed_xml', 'Feed body could not be parsed as XML.');
        }

        $root = $dom->documentElement;
        if ($root === null) {
            throw new FeedParseFailure('malformed_xml', 'Feed body is missing a document element.');
        }

        $rootName = strtolower($root->localName);
        $articles = [];

        if ($rootName === 'feed') {
            foreach ($root->getElementsByTagName('entry') as $entry) {
                $article = $this->parseAtomEntry($entry);
                if ($article !== null) {
                    $articles[] = $article;
                    if (count($articles) >= self::MAX_ITEMS) {
                        break;
                    }
                }
            }

            return $articles;
        }

        if ($rootName === 'rss') {
            $channel = $root->getElementsByTagName('channel')->item(0);
            if (! $channel instanceof DOMElement) {
                throw new FeedParseFailure('malformed_xml', 'RSS feed is missing <channel>.');
            }
            foreach ($channel->getElementsByTagName('item') as $item) {
                $article = $this->parseRssItem($item);
                if ($article !== null) {
                    $articles[] = $article;
                    if (count($articles) >= self::MAX_ITEMS) {
                        break;
                    }
                }
            }

            return $articles;
        }

        throw new FeedParseFailure('unsupported_feed', "Unsupported feed root element: {$root->localName}");
    }

    private function parseAtomEntry(DOMElement $entry): ?DiscoveredArticle
    {
        $url = null;
        foreach ($entry->getElementsByTagName('link') as $linkEl) {
            $rel = $linkEl->getAttribute('rel');
            $type = $linkEl->getAttribute('type');
            if ($rel !== '' && $rel !== 'alternate') {
                continue;
            }
            if ($type !== '' && ! str_contains(strtolower($type), 'html')) {
                continue;
            }
            $href = $linkEl->getAttribute('href');
            if ($href !== '') {
                $url = $href;
                break;
            }
        }
        if ($url === null) {
            return null;
        }

        $title = $this->firstChildText($entry, 'title');
        if ($title === '') {
            return null;
        }

        $publishedAt = $this->firstChildText($entry, 'published');
        if ($publishedAt === '') {
            $publishedAt = $this->firstChildText($entry, 'updated');
        }
        $id = $this->firstChildText($entry, 'id');

        return new DiscoveredArticle(
            url: $url,
            title: $title,
            publishedAt: $publishedAt === '' ? null : $publishedAt,
            rawId: $id === '' ? null : $id,
        );
    }

    private function parseRssItem(DOMElement $item): ?DiscoveredArticle
    {
        $url = $this->firstChildText($item, 'link');
        if ($url === '') {
            return null;
        }
        $title = $this->firstChildText($item, 'title');
        if ($title === '') {
            return null;
        }
        $publishedAt = $this->firstChildText($item, 'pubDate');
        $guid = $this->firstChildText($item, 'guid');

        return new DiscoveredArticle(
            url: $url,
            title: $title,
            publishedAt: $publishedAt === '' ? null : $publishedAt,
            rawId: $guid === '' ? null : $guid,
        );
    }

    private function firstChildText(DOMElement $parent, string $tagName): string
    {
        $needle = strtolower($tagName);
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && strtolower($node->localName) === $needle) {
                return trim($node->textContent);
            }
        }

        return '';
    }
}
