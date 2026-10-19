<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class IncidentReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
    }

    private function loginAdmin(): void
    {
        $this->admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($this->admin);
    }

    private function review(string $status = 'pending_review', array $analysis = []): IncidentReview
    {
        $document = OfficialDocument::factory()->create(['title' => '不正アクセスに関するお詫び']);

        return IncidentReview::query()->create([
            'official_document_id' => $document->id,
            'status' => $status,
            'analyzer' => 'sakura',
            'analysis' => array_merge([
                'organization' => 'テスト株式会社',
                'incident_title' => '不正アクセスによる個人情報流出',
                'affected_count_text' => '約1,200件',
                'data_types' => ['氏名', 'メールアドレス'],
                'disclosed_at' => '2026-10-19',
                'response_status' => '調査中',
                'summary' => '約1,200件の個人情報が流出した可能性。',
                'needs_human_review' => true,
            ], $analysis),
            'analyzed_at' => now(),
        ]);
    }

    public function test_review_queue_requires_an_admin(): void
    {
        $this->get('/admin/reviews')->assertRedirect(route('admin.login'));
    }

    public function test_pending_reviews_show_the_draft_and_the_official_source(): void
    {
        $review = $this->review();
        $this->review('approved', ['organization' => '承認済み株式会社']);
        $this->loginAdmin();

        $this->get('/admin/reviews')
            ->assertOk()
            ->assertSee('テスト株式会社')
            ->assertSee('約1,200件')
            ->assertSee('氏名、メールアドレス')
            ->assertSee($review->officialDocument->url)
            ->assertDontSee('承認済み株式会社');
    }

    public function test_approving_records_the_admin_and_time(): void
    {
        $review = $this->review();
        $this->loginAdmin();

        $this->post("/admin/reviews/{$review->id}/approve")->assertRedirect('/admin/reviews');

        $review->refresh();
        $this->assertSame('approved', $review->status);
        $this->assertSame($this->admin->id, $review->reviewed_by_admin_id);
        $this->assertNotNull($review->reviewed_at);
    }

    public function test_rejecting_records_the_admin(): void
    {
        $review = $this->review();
        $this->loginAdmin();

        $this->post("/admin/reviews/{$review->id}/reject")->assertRedirect('/admin/reviews');

        $this->assertSame('rejected', $review->fresh()->status);
        $this->assertSame($this->admin->id, $review->fresh()->reviewed_by_admin_id);
    }

    public function test_a_failed_analysis_cannot_be_approved(): void
    {
        $review = $this->review('analysis_failed');
        $this->loginAdmin();

        $this->post("/admin/reviews/{$review->id}/approve")->assertStatus(409);

        $this->assertSame('analysis_failed', $review->fresh()->status);
    }

    public function test_a_mock_draft_cannot_be_approved(): void
    {
        $review = $this->review();
        $review->update(['analyzer' => 'mock']);
        $this->loginAdmin();

        $this->post("/admin/reviews/{$review->id}/approve")->assertStatus(409);

        $this->assertSame('pending_review', $review->fresh()->status);
    }

    public function test_inactive_admin_cannot_decide(): void
    {
        $review = $this->review();
        Auth::guard('admin')->login(AdminUser::factory()->create(['is_active' => false]));

        $this->post("/admin/reviews/{$review->id}/approve")->assertForbidden();

        $this->assertSame('pending_review', $review->fresh()->status);
    }
}
