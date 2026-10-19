<?php

namespace Tests\Feature\Msnc;

use App\Jobs\Msnc\DiscoverNews;
use App\Jobs\Msnc\FetchArticle;
use App\Models\CrawlItem;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Services\Crawling\HostRateLimiter;
use App\Services\Crawling\MsncFetchGate;
use App\Services\Crawling\PythonArticleExtractor;
use App\Services\Crawling\RssAtomAdapter;
use App\Services\Crawling\SafeHttpFetcher;
use App\Services\Crawling\UrlPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MsncFetchGateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REAL_USER_AGENT = 'MorashiterCrawler/0.1 (+https://www.morashiter.com/; contact: crawler@morashiter.com)';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.allowed_schemes', ['http', 'https']);
        Config::set('crawler.trusted_private_hosts', ['fixture-news']);
        Config::set('crawler.host_rate.min_interval_ms', 0);
        Config::set('crawler.fetch_enabled', true);
        Config::set('crawler.fetch.user_agent', self::REAL_USER_AGENT);
    }

    private function source(): CrawlSource
    {
        return CrawlSource::factory()->create([
            'base_url' => 'http://fixture-news/feed.xml',
            'allowed_hosts' => ['fixture-news'],
            'is_enabled' => true,
        ]);
    }

    private function useProductionEnvironment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    public function test_gate_is_open_when_enabled_with_real_contact(): void
    {
        $this->useProductionEnvironment();

        $this->assertNull(app(MsncFetchGate::class)->closedReason());
    }

    public function test_gate_is_closed_when_fetch_is_disabled(): void
    {
        Config::set('crawler.fetch_enabled', false);

        $this->assertSame('fetch_disabled', app(MsncFetchGate::class)->closedReason());
    }

    public function test_gate_is_closed_in_production_with_placeholder_contact(): void
    {
        $this->useProductionEnvironment();
        Config::set('crawler.fetch.user_agent', 'MorashiterCrawler/0.1 (+contact: ops@example.invalid)');

        $this->assertSame('user_agent_placeholder', app(MsncFetchGate::class)->closedReason());
    }

    public function test_placeholder_contact_is_allowed_outside_production(): void
    {
        Config::set('crawler.fetch.user_agent', 'MorashiterCrawler/0.1 (+contact: ops@example.invalid)');

        $this->assertNull(app(MsncFetchGate::class)->closedReason());
    }

    public function test_fetch_enabled_defaults_to_off_when_env_is_unset(): void
    {
        $previous = getenv('MSNC_FETCH_ENABLED');
        putenv('MSNC_FETCH_ENABLED');
        unset($_ENV['MSNC_FETCH_ENABLED'], $_SERVER['MSNC_FETCH_ENABLED']);

        try {
            $config = require base_path('config/crawler.php');
            $this->assertFalse($config['fetch_enabled']);
        } finally {
            if ($previous !== false) {
                putenv("MSNC_FETCH_ENABLED={$previous}");
                $_ENV['MSNC_FETCH_ENABLED'] = $_SERVER['MSNC_FETCH_ENABLED'] = $previous;
            }
        }
    }

    public function test_command_queues_nothing_while_the_gate_is_closed(): void
    {
        Config::set('crawler.fetch_enabled', false);
        $this->source();
        Queue::fake();

        $this->artisan('msnc:discover', ['--all' => true])
            ->expectsOutputToContain('msnc fetching is closed: fetch_disabled')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_command_queues_nothing_in_production_with_placeholder_contact(): void
    {
        $this->useProductionEnvironment();
        Config::set('crawler.fetch.user_agent', 'MorashiterCrawler/0.1 (+contact: ops@example.invalid)');
        $this->source();
        Queue::fake();

        $this->artisan('msnc:discover', ['--all' => true])
            ->expectsOutputToContain('msnc fetching is closed: user_agent_placeholder')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_command_queues_discovery_in_production_when_the_gate_is_open(): void
    {
        $this->useProductionEnvironment();
        $source = $this->source();
        Queue::fake();

        $this->artisan('msnc:discover', ['--all' => true])->assertExitCode(0);

        Queue::assertPushed(DiscoverNews::class, fn (DiscoverNews $job): bool => $job->sourceId === $source->id);
    }

    public function test_already_queued_discovery_does_nothing_once_the_gate_closes(): void
    {
        Http::fake();
        $source = $this->source();
        Config::set('crawler.fetch_enabled', false);

        (new DiscoverNews($source->id))->handle(app(SafeHttpFetcher::class), app(RssAtomAdapter::class), app(UrlPolicy::class));

        $this->assertSame(0, CrawlRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_already_queued_article_is_skipped_without_fetching_once_the_gate_closes(): void
    {
        Http::fake();
        $source = $this->source();
        $run = CrawlRun::factory()->for($source, 'source')->create();
        $url = 'http://fixture-news/incidents/incident-001.html';
        $item = CrawlItem::factory()->for($source, 'source')->create([
            'crawl_run_id' => $run->id,
            'normalized_url' => $url,
            'url_hash' => hash('sha256', $url),
            'status' => 'queued',
        ]);
        Config::set('crawler.fetch_enabled', false);

        (new FetchArticle($item->id))->handle(app(SafeHttpFetcher::class), app(PythonArticleExtractor::class), app(HostRateLimiter::class));

        $item->refresh();
        $this->assertSame('skipped', $item->status);
        $this->assertSame('fetch_disabled', $item->error_code);
        $this->assertSame(0, $item->attempts);
        Http::assertNothingSent();
    }
}
