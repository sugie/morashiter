<?php

namespace App\Services\Crawling;

use App\Exceptions\HttpFetchFailure;
use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlSource;
use DOMDocument;
use DOMXPath;

class OfficialDomainPageCollector
{
    public function __construct(private readonly SafeHttpFetcher $fetcher, private readonly OfficialDocumentText $text) {}

    public function collect(string $host): array
    {
        $source = new CrawlSource(['allowed_hosts' => [$host]]);
        $pending = ['https://'.$host.'/'];
        $visited = [];
        $priorities = [];
        $pages = [];
        $final = null;
        $redirect = null;
        $limit = min(3, max(1, (int) config('domain_research.max_pages', 3)));
        while ($pending !== [] && count($visited) < $limit) {
            if (! config('domain_research.enabled')) {
                return compact('pages') + ['final_url' => $final, 'redirect_url' => $redirect, 'error' => 'disabled'];
            }
            $url = array_shift($pending);
            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;
            try {
                $result = $this->fetcher->fetch($url, $source, [
                    'timeout_seconds' => 5,
                    'max_redirects' => 2,
                    'on_redirect' => function (string $from, string $to) use ($host, &$final, &$redirect): void {
                        $final = $from;
                        if (strtolower((string) parse_url($to, PHP_URL_HOST)) !== $host
                            || parse_url($to, PHP_URL_SCHEME) !== 'https'
                            || ! in_array(parse_url($to, PHP_URL_PORT), [null, 443], true)) {
                            $redirect = $to;
                            throw new UrlPolicyViolation('research_redirect', $to, 'Research redirect requires human review.');
                        }
                    },
                ]);
                $final = $result->finalUrl;
                if ($result->status !== 200) {
                    continue;
                }
                $type = strtolower(explode(';', $result->header('Content-Type') ?? '')[0]);
                if (! in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
                    continue;
                }
                $document = new DOMDocument;
                $previous = libxml_use_internal_errors(true);
                $document->loadHTML('<?xml encoding="utf-8"?>'.$result->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                $xpath = new DOMXPath($document);
                $headings = '';
                foreach ($xpath->query('//title|//h1|//h2') as $node) {
                    $headings .= ' '.$node->textContent;
                }
                $overview = (bool) preg_match('/会社概要|企業概要|組織概要|法人概要|団体概要|省の概要|company\s+profile|corporate\s+profile|about\s+us/iu', $headings);
                $body = mb_substr($this->text->fromHtml($result->body), 0, 20000);
                $pages[] = ['url' => $final, 'text' => $body, 'is_overview' => $overview, 'sha256' => hash('sha256', $result->body), 'fetched_at' => now()->toIso8601String()];
                foreach ($document->getElementsByTagName('a') as $anchor) {
                    if (! preg_match('/会社概要|企業概要|組織概要|法人概要|団体概要|会社情報|企業情報|about|profile/iu', $anchor->textContent)) {
                        continue;
                    }
                    $href = trim($anchor->getAttribute('href'));
                    $absolute = $this->absolute($href, $final);
                    if ($absolute !== null && strtolower((string) parse_url($absolute, PHP_URL_HOST)) === $host
                        && parse_url($absolute, PHP_URL_SCHEME) === 'https'
                        && in_array(parse_url($absolute, PHP_URL_PORT), [null, 443], true)
                        && ! isset($visited[$absolute]) && ! in_array($absolute, $pending, true)) {
                        $pending[] = $absolute;
                        $priorities[$absolute] = preg_match('/会社概要|企業概要|組織概要|法人概要|団体概要|profile/iu', $anchor->textContent) ? 0 : 1;
                    }
                }
                usort($pending, fn (string $left, string $right): int => $priorities[$left] <=> $priorities[$right]);
            } catch (UrlPolicyViolation $e) {
                return ['pages' => $pages, 'final_url' => $final, 'redirect_url' => $redirect, 'error' => $e->violationCode];
            } catch (HttpFetchFailure $e) {
                return ['pages' => $pages, 'final_url' => $final, 'redirect_url' => $redirect, 'error' => $e->failureCode];
            }
        }

        return ['pages' => $pages, 'final_url' => $final, 'redirect_url' => $redirect, 'error' => null];
    }

    private function absolute(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || str_contains($href, '\\')) {
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
}
