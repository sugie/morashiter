<?php

namespace Tests\Feature\Admin;

use App\Jobs\Msic\FetchOfficialSource;
use App\Jobs\Msic\SearchOfficialAnnouncement;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\OfficialAnnouncementSearch;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Models\SourceDocument;
use App\Services\Crawling\HostResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnnouncementSearchFixtures as F;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

class OfficialAnnouncementSearchReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PAGE = '/admin/official-announcements';

    private OfficialDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'infrastructure.admin_allowed_ips' => ['203.0.113.10'],
            'announcement_search.enabled' => true, 'crawler.official_fetch_enabled' => true,
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([F::HOST => ['93.184.216.34']]));
    }

    private function login(bool $active = true): AdminUser
    {
        $admin = $active ? AdminUser::factory()->active()->create() : AdminUser::factory()->create(['is_active' => false]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function unresolved(): OfficialLinkCandidate
    {
        $this->domain = OfficialDomain::factory()->create(['host' => F::HOST]);
        $news = SourceDocument::factory()->create(['title' => F::NEWS_TITLE, 'published_on' => F::NEWS_DATE]);

        return OfficialLinkCandidate::factory()->create([
            'source_document_id' => $news->id, 'url' => F::TOP, 'url_hash' => hash('sha256', F::TOP),
            'host' => F::HOST, 'status' => 'fetched', 'error_code' => 'no_incident_link',
        ]);
    }

    private function completedSearch(OfficialLinkCandidate $candidate, array $overrides = []): OfficialAnnouncementSearch
    {
        return OfficialAnnouncementSearch::query()->create($overrides + [
            'official_link_candidate_id' => $candidate->id, 'status' => 'completed', 'analyzer' => 'sakura:fixture-model',
            'proposals' => [['url' => F::NOTICE, 'title' => '当社サービスに関するお知らせ', 'published_on' => '2026-10-19',
                'verdict' => 'same', 'quotes' => [F::NOTICE_QUOTE.'<script>alert(1)</script>'], 'rank' => 1]],
            'rule_candidates' => [['url' => F::PDF, 'title' => '個人情報漏えいに関するお詫び（PDF）', 'published_on' => '2026-10-15', 'score' => 7]],
            'pages_fetched' => 5, 'completed_at' => now(),
        ]);
    }

    public function test_page_shows_ai_proposals_with_escaped_quotes_and_an_add_form(): void
    {
        $candidate = $this->unresolved();
        $search = $this->completedSearch($candidate);
        $this->login();

        $response = $this->get(self::PAGE)->assertOk()
            ->assertSee('当社サービスに関するお知らせ')->assertSee(F::NOTICE)
            ->assertSee(F::NOTICE_QUOTE)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('このURLで追加')->assertSee('AIの候補は未確認です');
        $html = $response->getContent();
        $this->assertStringContainsString('name="suggested_by_search_id" value="'.$search->id.'"', $html);
        $this->assertStringContainsString('name="official_domain_id" value="'.$this->domain->id.'"', $html);
        $this->assertStringContainsString('name="url" value="'.F::NOTICE.'"', $html);
    }

    public function test_adding_a_proposal_creates_a_manual_submission_linked_to_the_search(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $candidate = $this->unresolved();
        $search = $this->completedSearch($candidate);
        $admin = $this->login();

        $this->post(self::PAGE, [
            'official_domain_id' => $this->domain->id, 'url' => F::NOTICE,
            'suggested_by_search_id' => $search->id, 'source_checked' => '1',
        ])->assertRedirect(self::PAGE)->assertSessionHasNoErrors();

        $submission = OfficialLinkCandidate::query()->whereNull('source_document_id')->sole();
        $this->assertSame(F::NOTICE, $submission->url);
        $this->assertSame($search->id, $submission->suggested_by_search_id);
        $this->assertSame($admin->id, $submission->submitted_by_admin_id);
        Queue::assertPushed(FetchOfficialSource::class);
    }

    public function test_a_rule_candidate_can_be_added_too(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $search = $this->completedSearch($this->unresolved());
        $this->login();

        $this->post(self::PAGE, [
            'official_domain_id' => $this->domain->id, 'url' => F::PDF,
            'suggested_by_search_id' => $search->id, 'source_checked' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($search->id, OfficialLinkCandidate::query()->whereNull('source_document_id')->sole()->suggested_by_search_id);
    }

    public function test_adding_requires_the_check_and_a_url_the_search_proposed(): void
    {
        Queue::fake([FetchOfficialSource::class]);
        $search = $this->completedSearch($this->unresolved());
        $this->login();

        $this->post(self::PAGE, ['official_domain_id' => $this->domain->id, 'url' => F::NOTICE, 'suggested_by_search_id' => $search->id])
            ->assertSessionHasErrors('source_checked');
        $this->post(self::PAGE, ['official_domain_id' => $this->domain->id, 'url' => 'https://'.F::HOST.'/news/2026/other.html', 'suggested_by_search_id' => $search->id, 'source_checked' => '1'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, OfficialLinkCandidate::query()->whereNull('source_document_id')->count());
    }

    public function test_search_button_queues_a_search_and_is_hidden_while_disabled(): void
    {
        Queue::fake([SearchOfficialAnnouncement::class]);
        $candidate = $this->unresolved();
        $admin = $this->login();

        $this->get(self::PAGE)->assertSee('AIで探す');
        $this->post(route('admin.official-announcements.search', $candidate))->assertRedirect(self::PAGE);

        $search = OfficialAnnouncementSearch::query()->sole();
        $this->assertSame('queued', $search->status);
        $this->assertSame($admin->id, $search->requested_by_admin_id);
        Queue::assertPushed(SearchOfficialAnnouncement::class);

        config()->set('announcement_search.enabled', false);
        $this->get(self::PAGE)->assertDontSee('AIで探す');
        $this->post(route('admin.official-announcements.search', $candidate))->assertStatus(409);
    }

    public function test_dismissing_records_the_reason_and_hides_the_proposals(): void
    {
        $search = $this->completedSearch($this->unresolved());
        $admin = $this->login();

        $this->post(route('admin.official-announcement-searches.dismiss', $search), ['reason' => '別の事件の発表'])
            ->assertRedirect(self::PAGE);

        $search->refresh();
        $this->assertSame('dismissed', $search->status);
        $this->assertSame($admin->id, $search->dismissed_by_admin_id);
        $this->assertSame('別の事件の発表', $search->dismiss_reason);
        $this->get(self::PAGE)->assertDontSee('このURLで追加');
        $this->post(route('admin.official-announcement-searches.dismiss', $search), ['reason' => ''])->assertSessionHasErrors('reason');
    }

    public function test_inactive_admin_cannot_search_or_dismiss(): void
    {
        Queue::fake([SearchOfficialAnnouncement::class]);
        $candidate = $this->unresolved();
        $search = $this->completedSearch($candidate);
        $this->login(active: false);

        $this->post(route('admin.official-announcements.search', $candidate))->assertForbidden();
        $this->post(route('admin.official-announcement-searches.dismiss', $search), ['reason' => 'x'])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_domain_registration_on_this_page_picks_the_company_from_a_list(): void
    {
        Company::factory()->create(['name' => '登録済み株式会社']);
        $this->login();

        $html = $this->get(self::PAGE)->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $form = '//form[@action="'.route('admin.official-domains.store').'"]';
        $values = [];
        foreach ($xpath->query($form.'//select[@name="company_choice"]//option') as $option) {
            $values[] = $option->getAttribute('value');
        }

        $this->assertSame(['', '登録済み株式会社', '__other__'], $values);
        $this->assertSame(1, $xpath->query($form.'//input[@name="company_name_other"]')->length);
        $this->assertSame(0, $xpath->query($form.'//input[@name="company_name"]')->length);
    }
}
