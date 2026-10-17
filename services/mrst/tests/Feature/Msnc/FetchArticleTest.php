<?php

namespace Tests\Feature\Msnc;

use App\Jobs\Msnc\FetchArticle;
use App\Models\CrawlItem;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Models\SourceDocument;
use App\Services\Crawling\PythonArticleExtractor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchArticleTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.allowed_schemes', ['http', 'https']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        Config::set('crawler.host_rate.min_interval_ms', 0);
        Storage::fake('local');

        $this->app->instance(PythonArticleExtractor::class, new class extends PythonArticleExtractor
        {
            public function extract(string $adapterCode, string $html, string $url): array
            {
                return [
                    'adapter_code'       => $adapterCode,
                    'extraction_version' => 'stub_v1',
                    'url'                => $url,
                    'title'              => 'テスト記事タイトル',
                    'published_on'       => '2026-10-01',
                    'body_text'          => '本文本文',
                ];
            }
        });
    }

    private function queuedItem(array $sourceOverrides = []): CrawlItem
    {
        $source = CrawlSource::factory()->create(array_merge([
            'base_url'      => 'http://fixture-news/feed.xml',
            'allowed_hosts' => ['fixture-news'],
            'extraction_config' => ['adapter_code' => 'fixture_news'],
        ], $sourceOverrides));
        $run = CrawlRun::factory()->for($source, 'source')->create();
        $url = 'http://fixture-news/incidents/incident-001.html';

        return CrawlItem::factory()
            ->for($source, 'source')
            ->create([
                'crawl_run_id'   => $run->id,
                'normalized_url' => $url,
                'url_hash'       => hash('sha256', $url),
                'status'         => 'queued',
            ]);
    }

    public function test_fetches_html_runs_extractor_and_persists_source_document(): void
    {
        $item = $this->queuedItem();
        Http::fake([
            $item->normalized_url => Http::response('<html>raw</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        $item->refresh();
        $this->assertSame('succeeded', $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertNotNull($item->source_document_id);

        $document = SourceDocument::query()->sole();
        $this->assertSame('テスト記事タイトル', $document->title);
        $this->assertSame('2026-10-01', $document->published_on->toDateString());
        $this->assertSame('stub_v1', $document->extraction_version);
        $this->assertSame($item->url_hash, $document->url_hash);
        Storage::disk('local')->assertExists($document->private_object_key);
    }

    public function test_identical_content_on_rerun_does_not_duplicate_source_document(): void
    {
        $item = $this->queuedItem();
        $html = '<html>same raw</html>';
        Http::fake([$item->normalized_url => Http::response($html, 200)]);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );
        $secondItem = CrawlItem::factory()
            ->for($item->source, 'source')
            ->create([
                'crawl_run_id'   => $item->crawl_run_id,
                'normalized_url' => $item->normalized_url.'?duplicate=1',
                'url_hash'       => hash('sha256', $item->normalized_url.'?duplicate=1'),
                'status'         => 'queued',
            ]);
        Http::fake([$secondItem->normalized_url => Http::response($html, 200)]);
        (new FetchArticle($secondItem->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        // Different url_hashes → separate SourceDocument rows (per schema UNIQUE)
        $this->assertSame(2, SourceDocument::query()->count());
    }

    public function test_already_running_item_is_not_reprocessed(): void
    {
        $item = $this->queuedItem();
        $item->update(['status' => 'running']);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        $this->assertSame('running', $item->fresh()->status);
        $this->assertSame(0, SourceDocument::query()->count());
        Http::assertNothingSent();
    }

    public function test_http_non_200_marks_item_failed(): void
    {
        $item = $this->queuedItem();
        Http::fake([$item->normalized_url => Http::response('not found', 404)]);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('http_non_200', $item->error_code);
        $this->assertSame(0, SourceDocument::query()->count());
    }

    public function test_url_policy_violation_marks_item_failed(): void
    {
        $item = $this->queuedItem(['allowed_hosts' => ['other.example.test']]);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('url_policy_violation', $item->error_code);
    }

    public function test_oversized_response_marks_item_failed_with_too_large(): void
    {
        $item = $this->queuedItem();
        $tooBig = str_repeat('x', 2 * 1024 * 1024 + 10);
        Http::fake([$item->normalized_url => Http::response($tooBig, 200)]);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('too_large', $item->error_code);
    }

    public function test_missing_adapter_code_marks_item_failed(): void
    {
        $item = $this->queuedItem(['extraction_config' => []]);
        Http::fake([$item->normalized_url => Http::response('<html>ok</html>', 200)]);

        (new FetchArticle($item->id))->handle(
            app(\App\Services\Crawling\SafeHttpFetcher::class),
            app(PythonArticleExtractor::class),
            app(\App\Services\Crawling\HostRateLimiter::class),
        );

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('adapter_missing', $item->error_code);
    }
}
