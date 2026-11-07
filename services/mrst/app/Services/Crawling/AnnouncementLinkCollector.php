<?php

namespace App\Services\Crawling;

use App\Exceptions\HttpFetchFailure;
use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlSource;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Throwable;

/**
 * Collects announcement candidates on one registered official host, by rules only:
 * robots.txt, sitemaps, RSS/Atom feeds and news listing pages. No page outside the host
 * is fetched and no AI is involved; the model later only picks among these URLs.
 */
class AnnouncementLinkCollector
{
    private const LISTING_PATTERN = '/お知らせ|ニュース|新着|プレス|IR|重要|情報公開|報道発表|記者発表|県政|市政|news|press|topics|information/iu';

    private const POLICY_PATTERN = '/方針|ポリシー|取り?扱い|規約|policy|privacy/iu';

    private const PATH_PATTERN = '#/(news|info|information|release|releases|press|topics|oshirase|important|notice)#i';

    private const DATE_PATTERN = '/(\d{4})\s*[.\/年-]\s*(\d{1,2})\s*[.\/月-]\s*(\d{1,2})\s*日?/u';

    private const MAX_LISTING_PAGES = 3;

    private const MAX_TEXT = 12000;

    private string $host = '';

    private int $fetched = 0;

    private int $limit = 10;

    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly HostRateLimiter $rateLimiter,
        private readonly OfficialPageLinkFinder $finder,
        private readonly OfficialDocumentText $text,
    ) {}

    /**
     * @return array{candidates: list<array{url: string, title: ?string, published_on: ?string, score: int}>, pages_fetched: int, error: ?string, disallow: list<string>}
     */
    public function collect(string $host, ?string $newsTitle, ?string $newsDate): array
    {
        $this->host = strtolower($host);
        $this->fetched = 0;
        $this->limit = max(1, (int) config('announcement_search.max_pages', 10));
        $origin = 'https://'.$this->host;

        $disallow = [];
        $sitemaps = [];
        $robots = $this->get($origin.'/robots.txt');
        if ($robots !== null && $robots->status === 200) {
            [$disallow, $sitemaps] = $this->parseRobots($robots->body);
        }
        if ($this->isDisallowed($origin.'/', $disallow)) {
            return $this->result([], 'robots_disallowed', $disallow);
        }
        $top = $this->get($origin.'/');
        if ($top === null || $top->status !== 200 || ! $this->isHtml($top)) {
            return $this->result([], 'top_page_unavailable', $disallow);
        }
        $topXpath = $this->xpath($top->body);

        /** @var array<string, array{url: string, title: ?string, published_on: ?string, source: string}> $entries */
        $entries = [];
        $listings = [];

        // Announcement-like links on the top page itself.
        foreach ($this->anchors($topXpath, $top->finalUrl) as [$url, $text]) {
            if (preg_match(self::LISTING_PATTERN, $text) && ! preg_match(self::POLICY_PATTERN, $text)
                && ! $this->finder->isTopPage($url) && count($listings) < self::MAX_LISTING_PAGES) {
                $listings[$url] = true;

                continue;
            }
            if (preg_match(OfficialPageLinkFinder::INCIDENT_PATTERN, $text) || preg_match(self::DATE_PATTERN, $text)) {
                $this->add($entries, $url, $text, $this->dateIn($text), 'top');
            }
        }

        // Sitemaps named in robots.txt (or the conventional one), at most two levels deep.
        $queue = array_map(fn (string $url): array => [$url, 1], $sitemaps !== [] ? $sitemaps : [$origin.'/sitemap.xml']);
        while ($queue !== []) {
            [$url, $depth] = array_shift($queue);
            if (! $this->sameHost($url) || $this->isDisallowed($url, $disallow)) {
                continue;
            }
            $response = $this->get($url);
            if ($response === null || $response->status !== 200) {
                continue;
            }
            foreach ($this->parseSitemap($response->body) as $item) {
                if ($item['sitemap'] && $depth < 2) {
                    $queue[] = [$item['url'], $depth + 1];
                } elseif (! $item['sitemap']) {
                    $this->add($entries, $item['url'], null, $item['date'], 'sitemap');
                }
            }
        }

        // RSS/Atom feeds announced by the top page.
        foreach ($topXpath->query('//link[@rel="alternate"][@href]') as $link) {
            $type = strtolower((string) $link->getAttribute('type'));
            $url = $this->absolute((string) $link->getAttribute('href'), $top->finalUrl);
            if (! str_contains($type, 'rss') && ! str_contains($type, 'atom') || $url === null || $this->isDisallowed($url, $disallow)) {
                continue;
            }
            $response = $this->get($url);
            if ($response === null || $response->status !== 200) {
                continue;
            }
            foreach ($this->parseFeed($response->body) as $item) {
                $this->add($entries, $item['url'], $item['title'], $item['date'], 'feed');
            }
        }

        // News listing pages linked from the top page.
        foreach (array_keys($listings) as $url) {
            if ($this->isDisallowed($url, $disallow)) {
                continue;
            }
            $response = $this->get($url);
            if ($response === null || $response->status !== 200 || ! $this->isHtml($response)) {
                continue;
            }
            foreach ($this->anchors($this->xpath($response->body), $response->finalUrl) as [$link, $text]) {
                // Category filters, paging and other navigation carry neither a date nor incident words.
                if ($this->dateIn($text) !== null || preg_match(OfficialPageLinkFinder::INCIDENT_PATTERN, $text)) {
                    $this->add($entries, $link, $text, $this->dateIn($text), 'listing');
                }
            }
        }

        return $this->result($this->rank($entries, $listings, $disallow, $newsTitle, $newsDate), null, $disallow);
    }

    /**
     * Text of one candidate page for the AI to check; same host and robots rules as collect().
     *
     * @param  list<string>  $disallow
     * @return array{text: string, final_url: string}|null
     */
    public function fetchText(string $host, string $url, array $disallow): ?array
    {
        $this->host = strtolower($host);
        $this->fetched = 0;
        $this->limit = 1;
        if (! $this->sameHost($url) || $this->isDisallowed($url, $disallow)) {
            return null;
        }
        $response = $this->get($url);
        if ($response === null || $response->status !== 200) {
            return null;
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        try {
            $text = match (true) {
                in_array($type, ['text/html', 'application/xhtml+xml'], true) => $this->text->fromHtml($response->body),
                $type === 'application/pdf' => $this->text->fromPdf($response->body),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
        if ($text === null || trim($text) === '') {
            return null;
        }

        return ['text' => mb_substr($text, 0, self::MAX_TEXT), 'final_url' => $response->finalUrl];
    }

    /**
     * @param  array<string, array{url: string, title: ?string, published_on: ?string, source: string}>  $entries
     * @param  array<string, true>  $listings
     * @param  list<string>  $disallow
     * @return list<array{url: string, title: ?string, published_on: ?string, score: int}>
     */
    private function rank(array $entries, array $listings, array $disallow, ?string $newsTitle, ?string $newsDate): array
    {
        $news = $this->date($newsDate);
        $listingPaths = array_map(fn (string $url): string => (string) parse_url($url, PHP_URL_PATH), array_keys($listings));
        $ranked = [];
        foreach ($entries as $url => $entry) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $title = $entry['title'];
            // A listing page itself, or the same listing with a filter/page query, is not an announcement.
            if (isset($listings[$url]) || in_array($path, $listingPaths, true) || $this->finder->isTopPage($url) || $this->isDisallowed($url, $disallow)
                || preg_match(self::POLICY_PATTERN, ($title ?? '').' '.$path)) {
                continue;
            }
            $date = $this->date($entry['published_on']);
            $inWindow = $news !== null && $date !== null
                && $date->greaterThanOrEqualTo($news->subDays(30)) && $date->lessThanOrEqualTo($news->addDays(7));
            if ($news !== null && $date !== null && ! $inWindow) {
                continue;
            }
            $pathSignal = (bool) preg_match(self::PATH_PATTERN, $path);
            // A bare sitemap URL needs some sign of being an announcement.
            if ($entry['source'] === 'sitemap' && $title === null && ! $inWindow && ! $pathSignal) {
                continue;
            }
            $score = 0;
            $score += $title !== null && preg_match(OfficialPageLinkFinder::INCIDENT_PATTERN, $title) ? 3 : 0;
            $score += $inWindow ? 2 : 0;
            $score += $title !== null ? 1 : 0;
            $score += str_ends_with(strtolower($path), '.pdf') ? 1 : 0;
            $score += $pathSignal ? 1 : 0;
            $score += $this->sharesWords($title, $newsTitle) ? 1 : 0;
            $ranked[] = ['url' => $url, 'title' => $title, 'published_on' => $entry['published_on'], 'score' => $score];
        }
        usort($ranked, fn (array $a, array $b): int => [$b['score'], $b['published_on'] ?? '', $a['url']] <=> [$a['score'], $a['published_on'] ?? '', $b['url']]);

        return array_slice($ranked, 0, max(1, (int) config('announcement_search.max_candidates', 15)));
    }

    /**
     * @param  array<string, array{url: string, title: ?string, published_on: ?string, source: string}>  $entries
     */
    private function add(array &$entries, string $url, ?string $text, ?string $date, string $source): void
    {
        $url = explode('#', $url)[0];
        if (! $this->sameHost($url)) {
            return;
        }
        $title = $text === null ? null : trim((string) preg_replace(['/\s+/u', self::DATE_PATTERN], [' ', ''], $text));
        $title = $title === '' || $title === null ? null : mb_substr(trim($title), 0, 200);
        if (isset($entries[$url])) {
            $entries[$url]['title'] ??= $title;
            $entries[$url]['published_on'] ??= $date;
            if ($entries[$url]['source'] === 'sitemap') {
                $entries[$url]['source'] = $source;
            }

            return;
        }
        $entries[$url] = ['url' => $url, 'title' => $title, 'published_on' => $date, 'source' => $source];
    }

    private function get(string $url): ?FetchResult
    {
        if ($this->fetched >= $this->limit || ! $this->sameHost($url)) {
            return null;
        }
        $this->fetched++;
        try {
            $this->rateLimiter->throttle($this->host);

            return $this->fetcher->fetch($url, new CrawlSource(['allowed_hosts' => [$this->host]]), [
                'timeout_seconds' => 8,
                'max_redirects' => 2,
                'on_redirect' => function (string $from, string $to): void {
                    if (! $this->sameHost($to)) {
                        throw new UrlPolicyViolation('search_redirect', $to, 'Announcement search stays on the official host.');
                    }
                },
            ]);
        } catch (UrlPolicyViolation|HttpFetchFailure) {
            return null;
        }
    }

    private function sameHost(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === $this->host
            && in_array(parse_url($url, PHP_URL_PORT), [null, 443], true);
    }

    private function isHtml(FetchResult $result): bool
    {
        return in_array(strtolower(trim(explode(';', (string) $result->header('Content-Type'))[0])), ['text/html', 'application/xhtml+xml'], true);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function parseRobots(string $body): array
    {
        $disallow = [];
        $sitemaps = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'sitemap') {
                $sitemaps[] = $value;

                continue;
            }
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);

                continue;
            }
            $inRules = true;
            if ($field === 'disallow' && $value !== '' && in_array('*', $agents, true)) {
                $disallow[] = $value;
            }
        }

        return [$disallow, $sitemaps];
    }

    /**
     * @param  list<string>  $disallow
     */
    private function isDisallowed(string $url, array $disallow): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        $path .= $query !== null && $query !== false ? '?'.$query : '';
        foreach ($disallow as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{url: string, date: ?string, sitemap: bool}>
     */
    private function parseSitemap(string $xml): array
    {
        $document = $this->xml($xml);
        if ($document === null) {
            return [];
        }
        $items = [];
        foreach (['url' => false, 'sitemap' => true] as $tag => $isIndex) {
            foreach ($document->getElementsByTagName($tag) as $node) {
                $loc = trim((string) $node->getElementsByTagName('loc')->item(0)?->textContent);
                $lastmod = trim((string) $node->getElementsByTagName('lastmod')->item(0)?->textContent);
                if ($loc !== '') {
                    $items[] = ['url' => $loc, 'date' => $this->dateIn($lastmod) ?? $this->isoDate($lastmod), 'sitemap' => $isIndex];
                }
            }
        }

        return $items;
    }

    /**
     * @return list<array{url: string, title: ?string, date: ?string}>
     */
    private function parseFeed(string $xml): array
    {
        $document = $this->xml($xml);
        if ($document === null) {
            return [];
        }
        $items = [];
        foreach ($document->getElementsByTagName('item') as $item) {
            $items[] = [
                'url' => trim((string) $item->getElementsByTagName('link')->item(0)?->textContent),
                'title' => trim((string) $item->getElementsByTagName('title')->item(0)?->textContent),
                'date' => $this->isoDate((string) $item->getElementsByTagName('pubDate')->item(0)?->textContent),
            ];
        }
        foreach ($document->getElementsByTagName('entry') as $entry) {
            $link = $entry->getElementsByTagName('link')->item(0);
            $items[] = [
                'url' => trim((string) $link?->getAttribute('href')),
                'title' => trim((string) $entry->getElementsByTagName('title')->item(0)?->textContent),
                'date' => $this->isoDate((string) ($entry->getElementsByTagName('published')->item(0)?->textContent
                    ?? $entry->getElementsByTagName('updated')->item(0)?->textContent)),
            ];
        }

        return array_values(array_filter($items, fn (array $item): bool => $item['url'] !== ''));
    }

    private function xml(string $xml): ?DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function anchors(DOMXPath $xpath, string $pageUrl): array
    {
        $anchors = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $url = $this->absolute((string) $anchor->getAttribute('href'), $pageUrl);
            $text = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent));
            if ($url !== null && $text !== '' && $this->sameHost($url)) {
                $anchors[] = [$url, $text];
            }
        }

        return $anchors;
    }

    private function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_contains($href, '\\') || preg_match('/^(mailto|javascript|tel):/i', $href)) {
            return null;
        }
        $href = explode('#', $href)[0];
        if (parse_url($href, PHP_URL_SCHEME) !== null) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        $origin = 'https://'.parse_url($base, PHP_URL_HOST);
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $directory = preg_replace('#/[^/]*$#', '/', parse_url($base, PHP_URL_PATH) ?: '/');

        return $origin.$directory.$href;
    }

    private function dateIn(string $text): ?string
    {
        if (! preg_match(self::DATE_PATTERN, $text, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }

    private function isoDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('announcement_search.budget_timezone', 'Asia/Tokyo'))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /** True when the title shares a word of three or more characters with the news headline. */
    private function sharesWords(?string $title, ?string $newsTitle): bool
    {
        if ($title === null || $newsTitle === null) {
            return false;
        }
        preg_match_all('/[\p{Han}\p{Katakana}ー]{3,}|[A-Za-z0-9]{3,}/u', $newsTitle, $words);
        foreach ($words[0] as $word) {
            if (mb_stripos($title, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{url: string, title: ?string, published_on: ?string, score: int}>  $candidates
     * @param  list<string>  $disallow
     * @return array{candidates: list<array{url: string, title: ?string, published_on: ?string, score: int}>, pages_fetched: int, error: ?string, disallow: list<string>}
     */
    private function result(array $candidates, ?string $error, array $disallow): array
    {
        return ['candidates' => $candidates, 'pages_fetched' => $this->fetched, 'error' => $error, 'disallow' => $disallow];
    }
}
