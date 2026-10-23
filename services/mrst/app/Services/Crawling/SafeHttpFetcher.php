<?php

namespace App\Services\Crawling;

use App\Exceptions\HttpFetchFailure;
use App\Models\CrawlSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class SafeHttpFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly UrlPolicy $urlPolicy,
        private readonly HttpFactory $http,
    ) {}

    /**
     * @param  array{if_none_match?: string, if_modified_since?: string, accept?: string, max_redirects?: int, timeout_seconds?: int, on_redirect?: callable(string, string): void}  $options
     */
    public function fetch(string $url, CrawlSource $source, array $options = []): FetchResult
    {
        $maxRedirects = (int) ($options['max_redirects'] ?? config('crawler.fetch.max_redirects', 3));
        $maxBytes = (int) config('crawler.fetch.max_body_bytes', 2 * 1024 * 1024);
        $connectTimeout = (int) config('crawler.fetch.connect_timeout_seconds', 5);
        $requestTimeout = (int) ($options['timeout_seconds'] ?? config('crawler.fetch.request_timeout_seconds', 30));
        $userAgent = (string) config('crawler.fetch.user_agent', 'MorashiterCrawler/0.1');

        ['url' => $current, 'ips' => $ips] = $this->urlPolicy->validateWithAddresses($url, $source);
        $headers = [
            'User-Agent' => $userAgent,
            'Accept' => $options['accept'] ?? 'text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.1',
            'Accept-Encoding' => 'identity',
        ];
        if (isset($options['if_none_match'])) {
            $headers['If-None-Match'] = $options['if_none_match'];
        }
        if (isset($options['if_modified_since'])) {
            $headers['If-Modified-Since'] = $options['if_modified_since'];
        }

        $startedAt = microtime(true);
        $redirects = 0;

        while (true) {
            try {
                $response = $this->http
                    ->withHeaders($headers)
                    ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$this->pin($current, $ips)]]])
                    ->timeout($requestTimeout)
                    ->connectTimeout($connectTimeout)
                    ->get($current);
            } catch (ConnectionException $e) {
                throw new HttpFetchFailure('timeout', $current, 'HTTP connection timed out: '.$e->getMessage(), $e);
            } catch (Throwable $e) {
                throw new HttpFetchFailure('transport', $current, 'HTTP transport error: '.$e->getMessage(), $e);
            }

            $status = $response->status();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $redirects++;
                if ($redirects > $maxRedirects) {
                    throw new HttpFetchFailure(
                        'too_many_redirects',
                        $url,
                        "Exceeded {$maxRedirects} redirects starting from {$url}.",
                    );
                }
                $location = $response->header('Location');
                if (! is_string($location) || $location === '') {
                    throw new HttpFetchFailure('transport', $current, 'Redirect response missing Location header.');
                }
                $next = $this->resolveLocation($current, $location);
                if (isset($options['on_redirect'])) {
                    ($options['on_redirect'])($current, $next);
                }
                ['url' => $current, 'ips' => $ips] = $this->urlPolicy->validateWithAddresses($next, $source);

                continue;
            }

            $body = $response->body();
            if (strlen($body) > $maxBytes) {
                throw new HttpFetchFailure(
                    'too_large',
                    $current,
                    'Response body exceeds configured max of '.$maxBytes.' bytes.',
                );
            }

            return new FetchResult(
                status: $status,
                headers: $response->headers(),
                body: $status === 304 ? '' : $body,
                finalUrl: $current,
                elapsedMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        }
    }

    /**
     * curl "host:port:addresses" entry that keeps the Host header and TLS SNI
     * while connecting only to addresses UrlPolicy already validated.
     *
     * @param  list<string>  $ips
     */
    private function pin(string $url, array $ips): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $addresses = array_map(fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $ips);

        return $parts['host'].':'.$port.':'.implode(',', $addresses);
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $location) === 1) {
            return $location;
        }

        $baseParts = parse_url($base);
        if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return $location;
        }

        $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDir = substr($basePath, 0, strrpos($basePath, '/') + 1);
        if ($baseDir === '') {
            $baseDir = '/';
        }

        return $origin.$baseDir.$location;
    }
}
