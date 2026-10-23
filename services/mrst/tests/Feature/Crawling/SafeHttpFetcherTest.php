<?php

namespace Tests\Feature\Crawling;

use App\Exceptions\HttpFetchFailure;
use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlSource;
use App\Services\Crawling\SafeHttpFetcher;
use App\Services\Crawling\UrlPolicy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

class SafeHttpFetcherTest extends TestCase
{
    private function fetcher(array $hostMap): SafeHttpFetcher
    {
        $policy = new UrlPolicy(new InMemoryHostResolver($hostMap));

        return new SafeHttpFetcher($policy, $this->app->make(HttpFactory::class));
    }

    private function source(array $allowedHosts): CrawlSource
    {
        $source = new CrawlSource;
        $source->allowed_hosts = $allowedHosts;

        return $source;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.allowed_schemes', ['http', 'https']);
        Config::set('crawler.allowed_ports', [80, 443]);
        Config::set('crawler.fetch.max_body_bytes', 2 * 1024 * 1024);
        Config::set('crawler.fetch.max_redirects', 3);
        Config::set('crawler.fetch.connect_timeout_seconds', 5);
        Config::set('crawler.fetch.request_timeout_seconds', 30);
        Config::set('crawler.fetch.user_agent', 'MorashiterCrawler/0.1 test');
    }

    public function test_get_returns_body_status_and_final_url(): void
    {
        Http::fake(['https://news.example.test/x' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html'])]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        $result = $fetcher->fetch('https://news.example.test/x', $source);

        $this->assertSame(200, $result->status);
        $this->assertSame('<html>ok</html>', $result->body);
        $this->assertSame('https://news.example.test/x', $result->finalUrl);
        $this->assertSame('text/html', $result->headers['Content-Type'][0]);
    }

    public function test_follows_redirect_up_to_the_configured_limit(): void
    {
        Http::fake([
            'https://news.example.test/a' => Http::response('', 301, ['Location' => '/b']),
            'https://news.example.test/b' => Http::response('ok', 200),
        ]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        $result = $fetcher->fetch('https://news.example.test/a', $source);

        $this->assertSame(200, $result->status);
        $this->assertSame('https://news.example.test/b', $result->finalUrl);
        $this->assertSame('ok', $result->body);
    }

    public function test_connection_is_pinned_to_the_addresses_that_passed_validation(): void
    {
        $pins = [];
        Http::fake(function (Request $request, array $options) use (&$pins) {
            $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return str_ends_with($request->url(), '/a')
                ? Http::response('', 302, ['Location' => 'https://cdn.example.test/b'])
                : Http::response('ok', 200);
        });
        $fetcher = $this->fetcher([
            'news.example.test' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'cdn.example.test' => ['93.184.216.35'],
        ]);

        $fetcher->fetch('https://news.example.test/a', $this->source(['news.example.test', 'cdn.example.test']));

        $this->assertSame([
            ['news.example.test:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'],
            ['cdn.example.test:443:93.184.216.35'],
        ], $pins);
    }

    public function test_exceeding_redirect_limit_fails(): void
    {
        Config::set('crawler.fetch.max_redirects', 2);
        Http::fake([
            'https://news.example.test/a' => Http::response('', 301, ['Location' => '/b']),
            'https://news.example.test/b' => Http::response('', 301, ['Location' => '/c']),
            'https://news.example.test/c' => Http::response('', 301, ['Location' => '/d']),
            'https://news.example.test/d' => Http::response('ok', 200),
        ]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        try {
            $fetcher->fetch('https://news.example.test/a', $source);
            $this->fail('Should have failed with too_many_redirects');
        } catch (HttpFetchFailure $e) {
            $this->assertSame('too_many_redirects', $e->failureCode);
        }
    }

    public function test_redirect_to_disallowed_host_is_rejected(): void
    {
        Http::fake([
            'https://news.example.test/a' => Http::response('', 302, ['Location' => 'https://other.example.test/b']),
        ]);
        $fetcher = $this->fetcher([
            'news.example.test' => ['93.184.216.34'],
            'other.example.test' => ['93.184.216.34'],
        ]);
        $source = $this->source(['news.example.test']);

        try {
            $fetcher->fetch('https://news.example.test/a', $source);
            $this->fail('Should have been rejected by UrlPolicy at redirect.');
        } catch (UrlPolicyViolation $e) {
            $this->assertSame('host_not_allowed', $e->violationCode);
        }
    }

    public function test_body_at_exact_max_size_is_allowed(): void
    {
        $max = 2 * 1024 * 1024;
        Http::fake(['https://news.example.test/x' => Http::response(str_repeat('a', $max), 200)]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        $result = $fetcher->fetch('https://news.example.test/x', $source);

        $this->assertSame(200, $result->status);
        $this->assertSame($max, strlen($result->body));
    }

    public function test_body_one_byte_over_max_is_rejected(): void
    {
        $max = 2 * 1024 * 1024;
        Http::fake(['https://news.example.test/x' => Http::response(str_repeat('a', $max + 1), 200)]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        try {
            $fetcher->fetch('https://news.example.test/x', $source);
            $this->fail('Should have been rejected as too_large.');
        } catch (HttpFetchFailure $e) {
            $this->assertSame('too_large', $e->failureCode);
        }
    }

    public function test_304_returns_empty_body(): void
    {
        Http::fake(['https://news.example.test/x' => Http::response('', 304)]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        $result = $fetcher->fetch('https://news.example.test/x', $source, [
            'if_none_match' => '"etag-abc"',
        ]);

        $this->assertSame(304, $result->status);
        $this->assertSame('', $result->body);
        Http::assertSent(fn ($request) => $request->header('If-None-Match')[0] === '"etag-abc"');
    }

    public function test_conditional_get_sends_if_modified_since(): void
    {
        Http::fake(['https://news.example.test/x' => Http::response('new', 200)]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        $fetcher->fetch('https://news.example.test/x', $source, [
            'if_modified_since' => 'Wed, 01 Oct 2026 12:00:00 GMT',
        ]);

        Http::assertSent(fn ($request) => $request->header('If-Modified-Since')[0] === 'Wed, 01 Oct 2026 12:00:00 GMT');
    }

    public function test_sends_configured_user_agent(): void
    {
        Http::fake(['https://news.example.test/x' => Http::response('ok', 200)]);
        $fetcher = $this->fetcher(['news.example.test' => ['93.184.216.34']]);
        $source = $this->source(['news.example.test']);

        $fetcher->fetch('https://news.example.test/x', $source);

        Http::assertSent(fn ($request) => $request->header('User-Agent')[0] === 'MorashiterCrawler/0.1 test');
    }

    public function test_initial_url_policy_violation_propagates(): void
    {
        $fetcher = $this->fetcher([]);
        $source = $this->source(['news.example.test']);

        $this->expectException(UrlPolicyViolation::class);
        $fetcher->fetch('https://news.example.test/x', $source);
    }
}
