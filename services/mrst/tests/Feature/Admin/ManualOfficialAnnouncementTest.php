<?php

namespace Tests\Feature\Admin;

use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Jobs\Msic\FetchOfficialSource;
use App\Models\AdminUser;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\HostResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryHostResolver;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class ManualOfficialAnnouncementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PAGE = '/admin/official-announcements';

    private const HOST = 'www.test-corp.example.co.jp';

    private const URL = 'https://www.test-corp.example.co.jp/news/notice.html';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        config()->set('crawler.official_fetch_enabled', true);
        config()->set('crawler.host_rate.min_interval_ms', 0);
        config()->set('ai.provider', 'mock');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([self::HOST => ['93.184.216.34']]));
    }

    private function login(): AdminUser
    {
        $admin = AdminUser::factory()->active()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function payload(string $url = self::URL): array
    {
        $domain = OfficialDomain::factory()->create(['host' => self::HOST]);

        return ['official_domain_id' => $domain->id, 'url' => $url, 'source_checked' => '1'];
    }

    public function test_submission_records_the_admin_and_queues_a_fetch_without_a_news_article(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $admin = $this->login();
        $this->post(self::PAGE, $this->payload())->assertRedirect(self::PAGE)->assertSessionHasNoErrors();
        $candidate = OfficialLinkCandidate::query()->sole();
        $this->assertNull($candidate->source_document_id);
        $this->assertSame($admin->id, $candidate->submitted_by_admin_id);
        $this->assertSame(self::URL, $candidate->url);
        $this->assertSame('accepted', $candidate->status);
        Queue::assertPushed(FetchOfficialSource::class, fn ($job) => $job->candidateId === $candidate->id && $job->afterCommit === true);
        $this->assertDatabaseCount('source_documents', 0);
    }

    public function test_form_and_submission_require_an_active_admin_and_allowed_ip(): void
    {
        $this->get(self::PAGE)->assertRedirect(route('admin.login'));
        $this->post(self::PAGE, [])->assertRedirect(route('admin.login'));
        $this->actingAs(AdminUser::factory()->create(['is_active' => false]), 'admin');
        $this->get(self::PAGE)->assertForbidden();
        $this->post(self::PAGE, [])->assertForbidden();
        $this->login();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2']);
        $this->get(self::PAGE)->assertForbidden();
        $this->post(self::PAGE, [])->assertForbidden();
        $this->assertDatabaseCount('official_link_candidates', 0);
    }

    public static function invalidUrls(): array
    {
        return [
            'http' => ['http://'.self::HOST.'/notice.html'],
            'credentials' => ['https://user:password@'.self::HOST.'/notice.html'],
            'port' => ['https://'.self::HOST.':8443/notice.html'],
            'different host' => ['https://other.example.jp/notice.html'],
            'subdomain' => ['https://sub.'.self::HOST.'/notice.html'],
            'private IP' => ['https://127.0.0.1/notice.html'],
            'top page' => ['https://'.self::HOST.'/'],
            'language root' => ['https://'.self::HOST.'/ja/'],
            'malformed' => ['not-a-url'],
            'control character' => ['https://'.self::HOST."/notice\n.html"],
            'too long' => ['https://'.self::HOST.'/'.str_repeat('a', 2050)],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_urls_do_not_create_work(string $url): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $this->login();
        $this->post(self::PAGE, $this->payload($url))->assertSessionHasErrors('url');
        $this->assertDatabaseCount('official_link_candidates', 0);
        Queue::assertNothingPushed();
    }

    public function test_private_dns_resolution_is_rejected(): void
    {
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([self::HOST => ['10.0.0.1']]));
        $this->login();
        $this->post(self::PAGE, $this->payload())->assertSessionHasErrors('url');
        $this->assertDatabaseCount('official_link_candidates', 0);
    }

    public function test_unregistered_domain_and_unchecked_source_are_rejected(): void
    {
        $this->login();
        $this->post(self::PAGE, ['official_domain_id' => 999999, 'url' => self::URL])
            ->assertSessionHasErrors(['official_domain_id', 'source_checked']);
        $this->assertDatabaseCount('official_link_candidates', 0);
    }

    public function test_duplicate_normalized_url_does_not_queue_twice_or_overwrite_audit(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $firstAdmin = $this->login();
        $payload = $this->payload();
        $this->post(self::PAGE, $payload)->assertSessionHasNoErrors();
        $this->login();
        $payload['url'] = 'https://WWW.TEST-CORP.EXAMPLE.CO.JP:443/news/notice.html?utm_source=test#section';
        $payload['status'] = 'fetched';
        $payload['submitted_by_admin_id'] = 999999;
        $this->post(self::PAGE, $payload)->assertSessionHasNoErrors();
        $candidate = OfficialLinkCandidate::query()->sole();
        $this->assertSame($firstAdmin->id, $candidate->submitted_by_admin_id);
        $this->assertSame('accepted', $candidate->status);
        Queue::assertPushed(FetchOfficialSource::class, 1);
    }

    public function test_disabled_fetching_keeps_work_waiting_with_an_explanation(): void
    {
        config()->set('crawler.official_fetch_enabled', false);
        Queue::fake([FetchOfficialSource::class]);
        $this->login();
        $this->post(self::PAGE, $this->payload())->assertSessionHasNoErrors();
        $this->assertSame('accepted', OfficialLinkCandidate::query()->sole()->status);
        Queue::assertNothingPushed();
        $this->get(self::PAGE)->assertOk()->assertSee('公式発表の取得が停止中');
    }

    public function test_manual_html_reaches_the_review_queue_without_publishing(): void
    {
        Storage::fake('local');
        Queue::fake([FetchOfficialSource::class, AnalyzeOfficialDocument::class]);
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response(file_get_contents(base_path('tests/fixtures/manual-official-notice.html')), 200, ['Content-Type' => 'text/html'])]);
        $this->login();
        $this->post(self::PAGE, $this->payload())->assertSessionHasNoErrors();
        $candidate = OfficialLinkCandidate::query()->sole();
        app()->call([new FetchOfficialSource($candidate->id), 'handle']);
        $document = OfficialDocument::query()->sole();
        Queue::assertPushed(AnalyzeOfficialDocument::class, fn ($job) => $job->officialDocumentId === $document->id);
        app()->call([new AnalyzeOfficialDocument($document->id), 'handle']);
        $this->assertSame('pending_review', IncidentReview::query()->sole()->status);
        $this->get('/admin/reviews')->assertOk()->assertSee(self::URL);
        $this->get(self::PAGE)->assertOk()->assertSee('審査待ち')->assertSee('mock');
        $this->assertDatabaseCount('incidents', 0);
        Http::assertSentCount(1);
    }

    public function test_pdf_url_reaches_analysis(): void
    {
        Queue::fake([FetchOfficialSource::class, AnalyzeOfficialDocument::class]);
        Storage::fake('local');
        $url = 'https://'.self::HOST.'/notice.pdf';
        Http::fake([$url => Http::response(PdfFixture::withText(['Test notice: service incident under investigation.']), 200, ['Content-Type' => 'application/pdf'])]);
        $this->login();
        $this->post(self::PAGE, $this->payload($url))->assertSessionHasNoErrors();
        app()->call([new FetchOfficialSource(OfficialLinkCandidate::query()->sole()->id), 'handle']);
        $document = OfficialDocument::query()->sole();
        $this->assertSame('application/pdf', $document->content_type);
        Queue::assertPushed(AnalyzeOfficialDocument::class, 1);
        app()->call([new AnalyzeOfficialDocument($document->id), 'handle']);
        $this->assertSame('pending_review', IncidentReview::query()->sole()->status);
    }

    public function test_failed_fetch_can_be_retried_once_and_not_while_waiting(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $admin = $this->login();
        $this->post(self::PAGE, $this->payload())->assertSessionHasNoErrors();
        $candidate = OfficialLinkCandidate::query()->sole();
        Http::fake([self::URL => Http::response('missing', 404)]);
        app()->call([new FetchOfficialSource($candidate->id), 'handle']);
        $this->get(self::PAGE)->assertOk()->assertSee('取得失敗')->assertSee('http_non_200');
        $this->post(self::PAGE.'/'.$candidate->id.'/retry')->assertRedirect(self::PAGE);
        $this->assertSame('accepted', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->error_code);
        $this->assertSame($admin->id, $candidate->fresh()->last_retried_by_admin_id);
        $this->assertNotNull($candidate->fresh()->last_retried_at);
        $this->post(self::PAGE.'/'.$candidate->id.'/retry')->assertStatus(409);
        Queue::assertPushed(FetchOfficialSource::class, 2);
    }

    public function test_retry_requires_admin_and_does_not_mutate_news_candidates(): void
    {
        $candidate = OfficialLinkCandidate::factory()->create(['status' => 'failed']);
        $this->post(self::PAGE.'/'.$candidate->id.'/retry')->assertRedirect(route('admin.login'));
        $this->login();
        $this->post(self::PAGE.'/'.$candidate->id.'/retry')->assertNotFound();
        $this->assertSame('failed', $candidate->fresh()->status);
    }

    public function test_existing_official_document_is_shown_without_another_fetch(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $this->login();
        $payload = $this->payload();
        OfficialDocument::factory()->create([
            'official_domain_id' => $payload['official_domain_id'],
            'url' => self::URL, 'url_hash' => hash('sha256', self::URL),
        ]);
        $this->post(self::PAGE, $payload)->assertSessionHasNoErrors();
        $this->assertSame('fetched', OfficialLinkCandidate::query()->sole()->status);
        Queue::assertNothingPushed();
        $this->get(self::PAGE)->assertOk()->assertSee('解析待ち')->assertSee(self::URL);
    }

    public function test_history_escapes_titles_and_shows_candidates_needing_manual_input(): void
    {
        $this->login();
        $candidate = OfficialLinkCandidate::factory()->create([
            'status' => 'fetched', 'error_code' => 'no_incident_link',
            'anchor_text' => '<script>alert(1)</script>',
        ]);
        $this->get(self::PAGE)->assertOk()->assertSee('公式発表を見つけられなかった候補')
            ->assertSee($candidate->url)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/admin')->assertSee(self::PAGE);
        $this->get('/admin/link-candidates')->assertSee(self::PAGE);
    }

    public function test_history_pages_independently_from_unresolved_candidates(): void
    {
        $this->login();
        for ($i = 1; $i <= 26; $i++) {
            OfficialLinkCandidate::factory()->create([
                'source_document_id' => null, 'status' => 'accepted',
                'url' => 'https://'.self::HOST.'/manual-'.$i.'.html',
            ]);
            OfficialLinkCandidate::factory()->create([
                'status' => 'fetched', 'error_code' => 'no_incident_link',
                'url' => 'https://'.self::HOST.'/unresolved-'.$i.'.html',
            ]);
        }
        $this->get(self::PAGE.'?page=2&unresolved_page=2')->assertOk()
            ->assertSee('手動追加の履歴のページ切り替え')
            ->assertSee('未発見候補のページ切り替え')
            ->assertSee('/manual-1.html')->assertDontSee('/manual-26.html')
            ->assertSee('/unresolved-26.html')->assertDontSee('/unresolved-1.html')
            ->assertSee('unresolved_page=2&amp;page=1', false)
            ->assertSee('page=2&amp;unresolved_page=1', false);
    }
}
