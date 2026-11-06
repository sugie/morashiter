<?php

namespace App\Services\Crawling;

use DOMDocument;
use DOMElement;

/**
 * Finds announcement links on a company's own page. News sites usually link to a company's
 * top page, so msic looks there for same-host links whose text mentions an incident.
 */
class OfficialPageLinkFinder
{
    private const MAX_LINKS = 3;

    public const INCIDENT_PATTERN = '/不正アクセス|漏えい|漏洩|流出|お詫び|サイバー攻撃|ランサムウェア|誤送信|誤送付|紛失|セキュリティインシデント|閲覧可能/u';

    /** Standing policy pages mention personal data or security but are not announcements. */
    private const POLICY_PATTERN = '/方針|ポリシー|取り?扱い|規約|policy/iu';

    private const TRACKING_PARAMETER = '/^(utm_.*|fbclid|gclid|_ebx|openExternalBrowser)$/i';

    /**
     * @return list<array{url: string, text: string}>
     */
    public function find(string $html, string $pageUrl): array
    {
        $pageHost = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            /** @var DOMElement $anchor */
            $text = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent));
            if ($text === '' || ! preg_match(self::INCIDENT_PATTERN, $text) || preg_match(self::POLICY_PATTERN, $text)) {
                continue;
            }
            $url = $this->withoutTracking($this->absolute(trim($anchor->getAttribute('href')), $pageUrl));
            if ($url === null || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $pageHost || isset($links[$url])) {
                continue;
            }
            $links[$url] = mb_substr($text, 0, 200);
            if (count($links) >= self::MAX_LINKS) {
                break;
            }
        }

        return array_map(fn (string $url, string $text): array => ['url' => $url, 'text' => $text], array_keys($links), array_values($links));
    }

    /**
     * A site root or a language root such as /ja/ is a top page, not an announcement.
     */
    public function isTopPage(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return (bool) preg_match('#^(/|/[a-z]{2}/?|/index\.(html?|php))?$#', $path);
    }

    private function withoutTracking(?string $url): ?string
    {
        if ($url === null || ! str_contains($url, '?')) {
            return $url;
        }
        [$base, $query] = explode('?', $url, 2);
        $kept = array_filter(
            explode('&', $query),
            fn (string $pair): bool => $pair !== '' && ! preg_match(self::TRACKING_PARAMETER, explode('=', $pair, 2)[0]),
        );

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }

    private function absolute(string $href, string $pageUrl): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        $href = (string) preg_replace('/#.*$/', '', $href);
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme !== '') {
            return in_array($scheme, ['http', 'https'], true) ? $href : null;
        }
        $base = parse_url($pageUrl);
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($href, '//')) {
            return $base['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $directory = preg_replace('#/[^/]*$#', '/', $base['path'] ?? '/');

        return $origin.$directory.$href;
    }
}
