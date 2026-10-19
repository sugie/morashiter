<?php

namespace Tests\Feature\Msic;

use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Jobs\Msic\FetchOfficialSource;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\HostResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

class FetchOfficialSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const HOST = 'www.test-corp.example.co.jp';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('crawler.official_fetch_enabled', true);
        Config::set('crawler.host_rate.min_interval_ms', 0);
        Storage::fake('local');
        Queue::fake([AnalyzeOfficialDocument::class]);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([
            self::HOST => ['93.184.216.34'],
            'evil.example.com' => ['93.184.216.35'],
        ]));
    }

    private function acceptedCandidate(string $url, bool $registered = true): OfficialLinkCandidate
    {
        if ($registered) {
            OfficialDomain::factory()->create(['host' => self::HOST]);
        }

        return OfficialLinkCandidate::factory()->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'host' => self::HOST,
            'status' => 'accepted',
        ]);
    }

    private function fetchCandidate(OfficialLinkCandidate $candidate): void
    {
        app()->call([new FetchOfficialSource($candidate->id), 'handle']);
    }

    private function page(string $title, string $body = ''): string
    {
        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$title}</title></head><body>{$body}</body></html>";
    }

    public function test_deep_link_is_stored_as_an_official_document(): void
    {
        $url = 'https://'.self::HOST.'/news/2026/1019.html';
        $candidate = $this->acceptedCandidate($url);
        $html = $this->page('不正アクセスに関するお詫び', '<p>当社は…</p>');
        Http::fake([$url => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $this->fetchCandidate($candidate);

        $document = OfficialDocument::query()->sole();
        $this->assertSame($url, $document->url);
        $this->assertSame('不正アクセスに関するお詫び', $document->title);
        $this->assertSame('text/html', $document->content_type);
        $this->assertSame(hash('sha256', $html), $document->content_sha256);
        $this->assertSame($candidate->id, $document->link_candidate_id);
        Storage::disk('local')->assertExists($document->private_object_key);
        $this->assertSame($html, Storage::disk('local')->get($document->private_object_key));
        $this->assertSame('fetched', $candidate->fresh()->status);
        Queue::assertPushed(AnalyzeOfficialDocument::class, fn (AnalyzeOfficialDocument $job): bool => $job->officialDocumentId === $document->id);
    }

    public function test_top_page_is_searched_for_incident_announcements(): void
    {
        $top = 'https://'.self::HOST.'/';
        $notice = 'https://'.self::HOST.'/news/2026/1019.html';
        $candidate = $this->acceptedCandidate($top);
        Http::fake([
            $top => Http::response($this->page('テスト社', '<a href="/news/2026/1019.html">不正アクセスによる情報流出のお詫び</a><a href="/products/">製品</a>'), 200, ['Content-Type' => 'text/html']),
            $notice => Http::response($this->page('情報流出のお詫び'), 200, ['Content-Type' => 'text/html']),
        ]);

        $this->fetchCandidate($candidate);

        $this->assertSame([$notice], OfficialDocument::query()->pluck('url')->all());
        $this->assertSame('fetched', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->error_code);
    }

    public function test_top_page_without_incident_links_needs_a_human(): void
    {
        $top = 'https://'.self::HOST.'/';
        $candidate = $this->acceptedCandidate($top);
        Http::fake([$top => Http::response($this->page('テスト社', '<a href="/company/">会社概要</a>'), 200, ['Content-Type' => 'text/html'])]);

        $this->fetchCandidate($candidate);

        $this->assertSame(0, OfficialDocument::query()->count());
        $this->assertSame('fetched', $candidate->fresh()->status);
        $this->assertSame('no_incident_link', $candidate->fresh()->error_code);
    }

    public function test_pdf_announcement_is_stored_with_its_type(): void
    {
        $url = 'https://'.self::HOST.'/ir/notice.pdf';
        $candidate = $this->acceptedCandidate($url);
        Http::fake([$url => Http::response('%PDF-1.7 fake', 200, ['Content-Type' => 'application/pdf'])]);

        $this->fetchCandidate($candidate);

        $document = OfficialDocument::query()->sole();
        $this->assertSame('application/pdf', $document->content_type);
        $this->assertNull($document->title);
        $this->assertStringEndsWith('.pdf', $document->private_object_key);
    }

    public function test_unregistered_host_is_never_fetched(): void
    {
        $url = 'https://'.self::HOST.'/news/1.html';
        $candidate = $this->acceptedCandidate($url, registered: false);
        Http::fake();

        $this->fetchCandidate($candidate);

        Http::assertNothingSent();
        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertSame('not_registered', $candidate->fresh()->error_code);
    }

    public function test_nothing_is_fetched_while_official_fetching_is_disabled(): void
    {
        Config::set('crawler.official_fetch_enabled', false);
        $candidate = $this->acceptedCandidate('https://'.self::HOST.'/news/1.html');
        Http::fake();

        $this->fetchCandidate($candidate);

        Http::assertNothingSent();
        $this->assertSame('accepted', $candidate->fresh()->status);
    }

    public function test_non_200_marks_the_candidate_failed(): void
    {
        $url = 'https://'.self::HOST.'/news/missing.html';
        $candidate = $this->acceptedCandidate($url);
        Http::fake([$url => Http::response('not found', 404)]);

        $this->fetchCandidate($candidate);

        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertSame('http_non_200', $candidate->fresh()->error_code);
        $this->assertSame(0, OfficialDocument::query()->count());
    }

    public function test_redirect_to_another_host_is_refused(): void
    {
        $url = 'https://'.self::HOST.'/news/1.html';
        $candidate = $this->acceptedCandidate($url);
        Http::fake([$url => Http::response('', 302, ['Location' => 'https://evil.example.com/phish.html'])]);

        $this->fetchCandidate($candidate);

        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertSame('url_policy_violation', $candidate->fresh()->error_code);
    }

    public function test_unsupported_content_type_is_not_stored(): void
    {
        $url = 'https://'.self::HOST.'/news/archive.zip';
        $candidate = $this->acceptedCandidate($url);
        Http::fake([$url => Http::response('PK', 200, ['Content-Type' => 'application/zip'])]);

        $this->fetchCandidate($candidate);

        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertSame('unsupported_content_type', $candidate->fresh()->error_code);
    }

    public function test_same_content_is_not_stored_twice(): void
    {
        $url = 'https://'.self::HOST.'/news/1.html';
        $first = $this->acceptedCandidate($url);
        $second = OfficialLinkCandidate::factory()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'host' => self::HOST, 'status' => 'accepted']);
        Http::fake([$url => Http::response($this->page('お詫び'), 200, ['Content-Type' => 'text/html'])]);

        $this->fetchCandidate($first);
        $this->fetchCandidate($second);

        $this->assertSame(1, OfficialDocument::query()->count());
        Queue::assertPushed(AnalyzeOfficialDocument::class, 1);
    }

    public function test_candidate_that_is_not_accepted_is_ignored(): void
    {
        $url = 'https://'.self::HOST.'/news/1.html';
        $candidate = $this->acceptedCandidate($url);
        $candidate->update(['status' => 'pending']);
        Http::fake();

        $this->fetchCandidate($candidate);

        Http::assertNothingSent();
        $this->assertSame('pending', $candidate->fresh()->status);
    }
}
