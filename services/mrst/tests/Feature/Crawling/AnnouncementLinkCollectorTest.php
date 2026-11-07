<?php

namespace Tests\Feature\Crawling;

use App\Services\Crawling\AnnouncementLinkCollector;
use App\Services\Crawling\HostResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AnnouncementSearchFixtures as F;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

class AnnouncementLinkCollectorTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['crawler.host_rate.min_interval_ms' => 0, 'announcement_search.max_pages' => 10]);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([F::HOST => ['93.184.216.34'], 'other.example' => ['93.184.216.35']]));
    }

    /**
     * @return array{candidates: list<array{url: string, title: ?string, published_on: ?string, score: int}>, pages_fetched: int, error: ?string}
     */
    private function collect(): array
    {
        return app(AnnouncementLinkCollector::class)->collect(F::HOST, F::NEWS_TITLE, F::NEWS_DATE);
    }

    public function test_collects_same_host_announcements_from_sitemap_rss_and_listing_pages(): void
    {
        Http::fake(F::site());

        $result = $this->collect();

        $urls = array_column($result['candidates'], 'url');
        $this->assertNull($result['error']);
        $this->assertContains(F::NOTICE, $urls, 'An announcement whose heading has no incident words is still collected from RSS and the listing.');
        $this->assertContains(F::PDF, $urls);
        $this->assertContains(F::SITEMAP_ONLY, $urls);
        $this->assertNotContains(F::DISALLOWED, $urls, 'robots.txt Disallow is respected.');
        $this->assertNotContains(F::OLD, $urls, 'Entries dated far before the news are dropped.');
        $this->assertNotContains(F::TOP, $urls);
        $this->assertNotContains('https://'.F::HOST.'/privacy/', $urls);
        $this->assertNotContains('https://'.F::HOST.'/products/', $urls, 'Plain section pages without an announcement signal are not candidates.');
        foreach ($urls as $url) {
            $this->assertSame(F::HOST, parse_url($url, PHP_URL_HOST));
        }
        $this->assertSame(F::PDF, $result['candidates'][0]['url'], 'An incident heading ranks first.');
        $notice = collect($result['candidates'])->firstWhere('url', F::NOTICE);
        $this->assertSame('当社サービスに関するお知らせ', $notice['title']);
        $this->assertSame('2026-10-19', $notice['published_on']);
        $this->assertSame(5, $result['pages_fetched']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'other.example') || str_contains($request->url(), '/secret/'));
    }

    public function test_listing_navigation_without_a_date_or_incident_words_is_not_a_candidate(): void
    {
        $site = F::site();
        $site['https://'.F::HOST.'/news/'] = Http::response(F::html(
            '<nav><a href="/news/?category=product">製品</a><a href="/news/?page=2">次へ</a><a href="/news/2026/catalog.html">製品カタログ</a></nav>'
            .'<ul><li><a href="/news/2026/1019.html">2026.10.19 当社サービスに関するお知らせ</a></li></ul>'
        ), 200, ['Content-Type' => 'text/html']);
        Http::fake($site);

        $urls = array_column($this->collect()['candidates'], 'url');

        $this->assertContains(F::NOTICE, $urls);
        $this->assertNotContains('https://'.F::HOST.'/news/?category=product', $urls);
        $this->assertNotContains('https://'.F::HOST.'/news/?page=2', $urls);
        $this->assertNotContains('https://'.F::HOST.'/news/2026/catalog.html', $urls);
    }

    public function test_government_press_release_listings_are_followed(): void
    {
        $site = F::site();
        $site[F::TOP] = Http::response(F::html('<a href="/press/">報道発表</a>'), 200, ['Content-Type' => 'text/html']);
        $site['https://'.F::HOST.'/press/'] = Http::response(F::html(
            '<ul><li><a href="/press/2026/1020-usb.html">令和8年10月20日 2026年10月20日 USBメモリの紛失について</a></li></ul>'
        ), 200, ['Content-Type' => 'text/html']);
        Http::fake($site);

        $this->assertContains('https://'.F::HOST.'/press/2026/1020-usb.html', array_column($this->collect()['candidates'], 'url'));
    }

    public function test_stops_at_the_page_limit(): void
    {
        config()->set('announcement_search.max_pages', 2);
        Http::fake(F::site());

        $result = $this->collect();

        $this->assertSame(2, $result['pages_fetched']);
        $this->assertLessThanOrEqual(2, count(Http::recorded()));
    }

    public function test_keeps_at_most_fifteen_candidates(): void
    {
        $site = F::site();
        $items = '';
        for ($i = 1; $i <= 30; $i++) {
            $items .= '<li><a href="/news/2026/item-'.$i.'.html">2026.10.19 お知らせ '.$i.'</a></li>';
        }
        $site['https://'.F::HOST.'/news/'] = Http::response(F::html('<ul>'.$items.'</ul>'), 200, ['Content-Type' => 'text/html']);
        Http::fake($site);

        $this->assertCount(15, $this->collect()['candidates']);
    }

    public function test_a_failed_top_page_is_reported_without_candidates(): void
    {
        Http::fake([F::TOP => Http::response('', 503), '*' => Http::response('', 404)]);

        $result = $this->collect();

        $this->assertSame([], $result['candidates']);
        $this->assertSame('top_page_unavailable', $result['error']);
    }

    public function test_a_redirect_to_another_host_is_not_followed(): void
    {
        $site = F::site();
        $site['https://'.F::HOST.'/news/'] = Http::response('', 302, ['Location' => 'https://other.example/news/']);
        Http::fake($site);

        $result = $this->collect();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'other.example'));
        $this->assertContains(F::NOTICE, array_column($result['candidates'], 'url'), 'RSS still supplies the announcement.');
    }
}
