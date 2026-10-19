<?php

namespace Tests\Feature\Msnc;

use App\Jobs\Msnc\DiscoverNews;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Services\Crawling\PythonArticleExtractor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MsncDiscoverCommandTest extends TestCase
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
            public function extract(string $adapterCode, string $html, string $url, ?string $containerSelector = null): array
            {
                return [
                    'adapter_code' => $adapterCode,
                    'extraction_version' => 'stub_v1',
                    'url' => $url,
                    'title' => 'stub title',
                    'published_on' => '2026-10-01',
                    'body_chars' => 480,
                    'official_links' => [],
                ];
            }
        });
    }

    private function atomFeed(): string
    {
        return file_get_contents(__DIR__.'/../../fixtures/feeds/atom.xml');
    }

    public function test_source_option_with_sync_runs_discover_now(): void
    {
        Http::fake(['http://fixture-news/feed.xml' => Http::response($this->atomFeed(), 200)]);
        $source = CrawlSource::factory()->create([
            'base_url' => 'http://fixture-news/feed.xml',
            'allowed_hosts' => ['fixture-news'],
            'is_enabled' => true,
        ]);

        $this->artisan('msnc:discover', ['--source' => $source->id, '--sync' => true])
            ->assertExitCode(0);

        $this->assertSame(1, CrawlRun::query()->count());
    }

    public function test_all_option_queues_job_for_each_enabled_source(): void
    {
        $enabledA = CrawlSource::factory()->create(['is_enabled' => true]);
        $enabledB = CrawlSource::factory()->create(['is_enabled' => true]);
        CrawlSource::factory()->create(['is_enabled' => false]);
        CrawlSource::factory()->create(['is_enabled' => true, 'crawler_code' => 'other']);
        CrawlSource::factory()->create(['is_enabled' => true, 'source_type' => 'official']);

        Queue::fake();
        $this->artisan('msnc:discover', ['--all' => true])->assertExitCode(0);

        Queue::assertPushed(DiscoverNews::class, 2);
        Queue::assertPushed(DiscoverNews::class, fn ($job) => $job->sourceId === $enabledA->id);
        Queue::assertPushed(DiscoverNews::class, fn ($job) => $job->sourceId === $enabledB->id);
    }

    public function test_missing_flags_fails(): void
    {
        $this->artisan('msnc:discover')->assertExitCode(2);
    }

    public function test_both_flags_fails(): void
    {
        $this->artisan('msnc:discover', ['--source' => 1, '--all' => true])->assertExitCode(2);
    }

    public function test_unknown_source_id_produces_no_dispatch(): void
    {
        Queue::fake();
        $this->artisan('msnc:discover', ['--source' => 99999])->assertExitCode(0);
        Queue::assertNothingPushed();
    }
}
