<?php

namespace Tests\Feature\Crawling;

use App\Models\CrawlSource;
use App\Services\Crawling\SafeHttpFetcher;
use App\Services\Crawling\SystemDnsHostResolver;
use App\Services\Crawling\UrlPolicy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SafeHttpFetcherLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.allowed_schemes', ['http']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        Config::set('crawler.fetch.max_body_bytes', 2 * 1024 * 1024);
        Config::set('crawler.fetch.max_redirects', 3);
        Config::set('crawler.fetch.connect_timeout_seconds', 3);
        Config::set('crawler.fetch.request_timeout_seconds', 10);

        $resolver = new SystemDnsHostResolver();
        if ($resolver->resolve('fixture-news') === []) {
            $this->markTestSkipped('fixture-news container is not reachable from the app container.');
        }
    }

    private function fetcher(): SafeHttpFetcher
    {
        return new SafeHttpFetcher(
            new UrlPolicy(new SystemDnsHostResolver()),
            $this->app->make(HttpFactory::class),
        );
    }

    private function source(): CrawlSource
    {
        $source = new CrawlSource();
        $source->allowed_hosts = ['fixture-news'];

        return $source;
    }

    public function test_live_feed_fetch_returns_atom_document(): void
    {
        $result = $this->fetcher()->fetch('http://fixture-news/feed.xml', $this->source());

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('<feed', $result->body);
        $this->assertStringContainsString('incident-001.html', $result->body);
    }

    public function test_live_article_fetch_returns_html(): void
    {
        $result = $this->fetcher()->fetch(
            'http://fixture-news/incidents/incident-001.html',
            $this->source(),
        );

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('<article>', $result->body);
        $this->assertStringContainsString('テスト企業A', $result->body);
    }

    public function test_live_missing_url_returns_404(): void
    {
        $result = $this->fetcher()->fetch(
            'http://fixture-news/incidents/does-not-exist.html',
            $this->source(),
        );

        $this->assertSame(404, $result->status);
    }
}
