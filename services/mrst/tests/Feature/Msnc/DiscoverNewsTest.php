<?php

namespace Tests\Feature\Msnc;

use App\Exceptions\FeedParseFailure;
use App\Jobs\Msnc\DiscoverNews;
use App\Jobs\Msnc\FetchArticle;
use App\Models\CrawlItem;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Services\Crawling\RssAtomAdapter;
use App\Services\Crawling\SafeHttpFetcher;
use App\Services\Crawling\UrlPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DiscoverNewsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.allowed_schemes', ['http', 'https']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        Config::set('crawler.max_items_per_run', 100);
        Config::set('crawler.host_rate.min_interval_ms', 0);
    }

    private function source(array $overrides = []): CrawlSource
    {
        return CrawlSource::factory()->create(array_merge([
            'base_url' => 'http://fixture-news/feed.xml',
            'allowed_hosts' => ['fixture-news'],
            'is_enabled' => true,
            'crawler_code' => 'msnc',
            'source_type' => 'news',
            'extraction_config' => ['adapter_code' => 'fixture_news'],
        ], $overrides));
    }

    private function atomFeed(): string
    {
        return file_get_contents(__DIR__.'/../../fixtures/feeds/atom.xml');
    }

    public function test_three_articles_from_feed_are_persisted_as_crawl_items(): void
    {
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();

        (new DiscoverNews($source->id))->handle(
            app(SafeHttpFetcher::class),
            app(RssAtomAdapter::class),
            app(UrlPolicy::class),
        );

        $this->assertSame(3, CrawlItem::query()->count());
        $run = CrawlRun::query()->sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(3, $run->discovered_count);
        $this->assertNotNull($run->discovery_finished_at);
    }

    private function discover(CrawlSource $source): void
    {
        (new DiscoverNews($source->id))->handle(
            app(SafeHttpFetcher::class),
            app(RssAtomAdapter::class),
            app(UrlPolicy::class),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function priorItem(CrawlSource $source, string $url, array $attributes): CrawlItem
    {
        $run = CrawlRun::factory()->for($source, 'source')->create();

        return CrawlItem::factory()->for($source, 'source')->create(array_merge([
            'crawl_run_id' => $run->id,
            'normalized_url' => $url,
            'url_hash' => hash('sha256', $url),
        ], $attributes));
    }

    public function test_rerun_against_same_source_does_not_refetch_known_urls(): void
    {
        Queue::fake();
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();

        $this->discover($source);
        $this->discover($source);

        $this->assertSame(3, CrawlItem::query()->count());
        Queue::assertPushed(FetchArticle::class, 3);
        $secondRun = CrawlRun::query()->latest('id')->first();
        $this->assertSame('succeeded', $secondRun->status);
        $this->assertSame(0, $secondRun->discovered_count);
        $this->assertSame(3, $secondRun->skipped_count);
    }

    public function test_url_that_already_succeeded_is_skipped(): void
    {
        Queue::fake();
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();
        $this->priorItem($source, 'http://fixture-news/incidents/incident-001.html', ['status' => 'succeeded']);

        $this->discover($source);

        Queue::assertPushed(FetchArticle::class, 2);
        $run = CrawlRun::query()->latest('id')->first();
        $this->assertSame(2, $run->discovered_count);
        $this->assertSame(1, $run->skipped_count);
    }

    public function test_url_that_failed_fewer_than_three_times_is_retried(): void
    {
        Queue::fake();
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();
        $url = 'http://fixture-news/incidents/incident-001.html';
        $this->priorItem($source, $url, ['status' => 'failed', 'error_code' => 'http_non_200']);
        $this->priorItem($source, $url, ['status' => 'failed', 'error_code' => 'http_non_200']);

        $this->discover($source);

        Queue::assertPushed(FetchArticle::class, 3);
        $this->assertSame(0, CrawlRun::query()->latest('id')->first()->skipped_count);
    }

    public function test_url_that_failed_three_times_is_no_longer_retried(): void
    {
        Queue::fake();
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();
        $url = 'http://fixture-news/incidents/incident-001.html';
        foreach (range(1, 3) as $attempt) {
            $this->priorItem($source, $url, ['status' => 'failed', 'error_code' => 'http_non_200']);
        }

        $this->discover($source);

        Queue::assertPushed(FetchArticle::class, 2);
        $this->assertSame(1, CrawlRun::query()->latest('id')->first()->skipped_count);
    }

    public function test_known_url_from_a_different_source_is_still_fetched(): void
    {
        Queue::fake();
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();
        $other = $this->source(['name' => 'Other Source']);
        $this->priorItem($other, 'http://fixture-news/incidents/incident-001.html', ['status' => 'succeeded']);

        $this->discover($source);

        Queue::assertPushed(FetchArticle::class, 3);
    }

    public function test_disabled_source_is_rejected_without_creating_a_run(): void
    {
        $source = $this->source(['is_enabled' => false]);

        try {
            (new DiscoverNews($source->id))->handle(
                app(SafeHttpFetcher::class),
                app(RssAtomAdapter::class),
                app(UrlPolicy::class),
            );
            $this->fail('Disabled source must not be processed.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, CrawlRun::query()->count());
    }

    public function test_wrong_crawler_code_is_rejected(): void
    {
        $source = $this->source(['crawler_code' => 'other']);

        $this->expectException(RuntimeException::class);
        (new DiscoverNews($source->id))->handle(
            app(SafeHttpFetcher::class),
            app(RssAtomAdapter::class),
            app(UrlPolicy::class),
        );
    }

    public function test_feed_http_500_marks_run_failed(): void
    {
        Http::fake(['http://fixture-news/feed.xml' => Http::response('oops', 500)]);
        $source = $this->source();

        try {
            (new DiscoverNews($source->id))->handle(
                app(SafeHttpFetcher::class),
                app(RssAtomAdapter::class),
                app(UrlPolicy::class),
            );
            $this->fail('5xx should surface as failure.');
        } catch (RuntimeException) {
        }

        $run = CrawlRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('discovery_failed', $run->error_code);
    }

    public function test_malformed_feed_marks_run_failed_with_feed_parse_failure(): void
    {
        Http::fake(['http://fixture-news/feed.xml' => Http::response('<not-xml', 200)]);
        $source = $this->source();

        try {
            (new DiscoverNews($source->id))->handle(
                app(SafeHttpFetcher::class),
                app(RssAtomAdapter::class),
                app(UrlPolicy::class),
            );
            $this->fail('Malformed feed should surface as failure.');
        } catch (FeedParseFailure) {
        }

        $run = CrawlRun::query()->sole();
        $this->assertSame('failed', $run->status);
    }

    public function test_discovered_count_is_capped_by_configured_limit(): void
    {
        Config::set('crawler.max_items_per_run', 2);
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = $this->source();

        (new DiscoverNews($source->id))->handle(
            app(SafeHttpFetcher::class),
            app(RssAtomAdapter::class),
            app(UrlPolicy::class),
        );

        $this->assertSame(2, CrawlItem::query()->count());
        $this->assertSame(2, CrawlRun::query()->sole()->discovered_count);
    }
}
