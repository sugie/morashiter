<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\CrawlRun;
use App\Models\Incident;
use App\Models\IncidentReview;
use App\Models\IncidentRevision;
use App\Models\OfficialDocument;
use App\Models\OfficialLinkCandidate;
use App\Models\SiteRelease;
use App\Services\Publishing\PublicSnapshotBuilder;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        config()->set('domain_research.enabled', false);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
    }

    public function test_dashboard_requires_an_active_admin_and_allowed_ip(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->actingAs(AdminUser::factory()->create(['is_active' => false]), 'admin')->get('/admin')->assertForbidden();
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->get('/admin')->assertForbidden();
    }

    public function test_empty_dashboard_shows_zero_counts_and_no_publish_history(): void
    {
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', [
            'pending_hosts' => 0, 'pending_links' => 0, 'fetch_waiting' => 0,
            'fetch_running' => 0, 'fetch_failed' => 0, 'missing_links' => 0,
            'analysis_waiting' => 0, 'review_queue' => 0, 'review_ready' => 0,
            'analysis_failed' => 0, 'publish_new' => 0,
        ])->assertViewHas('hasUnpublishedChanges', false)
            ->assertSee('公開履歴はありません')->assertSee('審査キュー')
            ->assertSee('公式発表リンクの確認待ち')->assertSee('公開待ち（新規）');
    }

    public function test_dashboard_counts_stages_without_counting_approved_or_rejected_reviews(): void
    {
        OfficialLinkCandidate::factory()->count(2)->create(['host' => 'alpha.example.jp']);
        OfficialLinkCandidate::factory()->create(['host' => 'beta.example.jp']);
        foreach (['accepted', 'running', 'failed', 'rejected', 'fetched'] as $status) {
            OfficialLinkCandidate::factory()->create(['status' => $status]);
        }
        OfficialLinkCandidate::factory()->create(['status' => 'fetched', 'error_code' => 'no_incident_link']);
        foreach (['pending_review', 'pending_review', 'analysis_failed', 'approved', 'rejected'] as $status) {
            IncidentReview::query()->create([
                'official_document_id' => OfficialDocument::factory()->create()->id,
                'status' => $status, 'analyzer' => 'sakura',
            ]);
        }
        OfficialDocument::factory()->create();
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', [
            'pending_hosts' => 2, 'pending_links' => 3, 'fetch_waiting' => 1,
            'fetch_running' => 1, 'fetch_failed' => 1, 'missing_links' => 1,
            'analysis_waiting' => 1, 'review_queue' => 3, 'review_ready' => 2,
            'analysis_failed' => 1, 'publish_new' => 0,
        ])->assertSee(route('admin.link-candidates.index').'#retrieval-issues', false)
            ->assertSee(route('admin.reviews.index'), false);
    }

    public function test_counts_include_more_than_the_list_display_limit(): void
    {
        OfficialLinkCandidate::factory()->count(101)->create(['host' => 'alpha.example.jp']);
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', fn (array $counts): bool => $counts['pending_links'] === 101);
    }

    public function test_first_publication_counts_only_approved_non_withdrawn_incidents_once(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        Incident::query()->where('display_code', 'INC-001')->update(['withdrawn_at' => now()]);
        Incident::query()->create(['display_code' => 'INC-006', 'origin' => 'manual']);
        $revision = IncidentRevision::query()->whereHas('incident', fn ($query) => $query->where('display_code', 'INC-002'))->sole();
        $revision->replicate()->fill(['revision_number' => 2])->save();
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', fn (array $counts): bool => $counts['publish_new'] === 4)
            ->assertViewHas('hasUnpublishedChanges', true);
    }

    public function test_new_publish_count_uses_live_release_not_a_newer_failed_attempt(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        SiteRelease::query()->create(['status' => 'live', 'incident_ids' => ['INC-001', 'INC-002', 'INC-003'], 'snapshot_sha256' => str_repeat('a', 64)]);
        SiteRelease::query()->create(['status' => 'failed', 'incident_ids' => ['INC-001', 'INC-002', 'INC-003', 'INC-004', 'INC-005']]);
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', fn (array $counts): bool => $counts['publish_new'] === 2)
            ->assertViewHas('hasUnpublishedChanges', true)->assertSee('公開失敗');
    }

    private function liveRelease(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        SiteRelease::query()->create([
            'status' => 'live', 'incident_ids' => ['INC-001', 'INC-002', 'INC-003', 'INC-004', 'INC-005'],
            'snapshot_sha256' => app(PublicSnapshotBuilder::class)->sha256(),
        ]);
    }

    public function test_published_content_is_not_reported_as_waiting(): void
    {
        $this->liveRelease();
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', fn (array $counts): bool => $counts['publish_new'] === 0)
            ->assertViewHas('hasUnpublishedChanges', false)->assertSee('承認済みの内容は公開に反映済みです');
    }

    public function test_corrections_are_reported_even_when_no_new_incidents_exist(): void
    {
        $this->liveRelease();
        $revision = IncidentRevision::query()->firstOrFail();
        $revision->replicate()->fill(['revision_number' => 2, 'summary' => '訂正された概要'])->save();
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', fn (array $counts): bool => $counts['publish_new'] === 0)
            ->assertViewHas('hasUnpublishedChanges', true)->assertSee('公開に未反映の変更があります');
    }

    public function test_withdrawals_are_reported_as_unpublished_changes(): void
    {
        $this->liveRelease();
        Incident::query()->firstOrFail()->update(['withdrawn_at' => now(), 'withdrawal_reason' => '訂正のため']);
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('counts', fn (array $counts): bool => $counts['publish_new'] === 0)
            ->assertViewHas('hasUnpublishedChanges', true);
    }

    public function test_shows_latest_crawl_and_publishing_disabled_state(): void
    {
        config()->set('msgn.enabled', false);
        CrawlRun::factory()->create(['status' => 'failed']);
        $latest = CrawlRun::factory()->create(['status' => 'succeeded', 'discovered_count' => 0, 'skipped_count' => 20, 'started_at' => '2026-10-26 03:00:00+00']);
        $this->actingAs(AdminUser::factory()->active()->create(), 'admin');

        $this->get('/admin')->assertViewHas('latestCrawl', fn (CrawlRun $run): bool => $run->id === $latest->id)
            ->assertSee('スキップ 20 件')->assertSee('2026/10/26 12:00')->assertSee('公開機能は無効です');
    }
}
