<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Incident;
use App\Models\IncidentReview;
use App\Models\IncidentRevision;
use App\Models\OfficialDocument;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncidentApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($this->admin);
    }

    private function review(array $overrides = []): IncidentReview
    {
        $document = OfficialDocument::factory()->create([
            'url' => 'https://www.test-corp.example.co.jp/news/1019.html',
            'title' => '不正アクセスに関するお詫び',
        ]);

        return IncidentReview::query()->create(array_merge([
            'official_document_id' => $document->id,
            'status' => 'pending_review',
            'analyzer' => 'sakura',
            'attempts' => 1,
            'analysis' => [
                'organization' => 'テスト株式会社',
                'incident_title' => '会員サイトへの不正アクセス',
                'affected_count_text' => '約1,200件',
                'data_types' => ['氏名', 'メールアドレス'],
                'disclosed_at' => '2026-10-19',
                'response_status' => '調査中',
                'summary' => 'テスト株式会社は不正アクセスにより約1,200件の個人情報が流出した可能性を公表した。',
                'needs_human_review' => true,
            ],
            'analyzed_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'テスト会員サイト',
            'company' => 'テスト株式会社',
            'type' => '不正アクセス',
            'disclosure' => '漏えいの可能性',
            'report' => '',
            'brief' => '会員サイトへの不正アクセスで個人情報流出の可能性',
            'quantity' => '約1,200件',
            'scale' => '約1,200件',
            'data_type' => '氏名・メールアドレス',
            'date' => '2026/10/19',
            'status' => '調査中',
            'summary' => '会員サイトへの不正アクセスにより、約1,200件の個人情報が流出した可能性を公表しました。',
            'target' => '会員サイトの利用者。対象かどうかは会社からの案内で確認してください。',
            'action' => '会社を装う連絡に注意し、公式窓口で確認してください。',
            'response' => '不正アクセスを遮断し、調査中。',
            'source' => 'https://www.test-corp.example.co.jp/news/1019.html',
            'contact' => 'https://www.test-corp.example.co.jp/contact/',
            'password' => 'password',
        ], $overrides);
    }

    public function test_approval_form_is_prefilled_from_the_ai_draft_and_the_official_source(): void
    {
        $review = $this->review();

        $this->get("/admin/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertSee('value="テスト株式会社"', false)
            ->assertSee('value="約1,200件"', false)
            ->assertSee('value="2026/10/19"', false)
            ->assertSee('value="氏名・メールアドレス"', false)
            ->assertSee('value="https://www.test-corp.example.co.jp/news/1019.html"', false)
            ->assertSee('テスト株式会社は不正アクセスにより約1,200件');
    }

    public function test_approving_creates_a_new_incident_with_an_approved_revision(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        $review = $this->review();

        $this->post("/admin/reviews/{$review->id}/approve", $this->validForm())->assertRedirect('/admin/reviews');

        $incident = Incident::query()->where('display_code', 'INC-006')->sole();
        $revision = $incident->currentRevision;
        $this->assertSame('review', $incident->origin);
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame('approved', $revision->status);
        $this->assertSame('manual', $revision->approval_method);
        $this->assertSame($this->admin->id, $revision->approved_by_admin_id);
        $this->assertNotNull($revision->approved_at);
        $this->assertSame($review->id, $revision->incident_review_id);
        $this->assertSame('約1,200件', $revision->quantity);
        $this->assertNull($revision->report);
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertSame($this->admin->id, $review->fresh()->reviewed_by_admin_id);
    }

    public function test_approving_into_an_existing_incident_adds_a_new_revision(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        $incident = Incident::query()->where('display_code', 'INC-002')->sole();
        $review = $this->review();

        $this->post("/admin/reviews/{$review->id}/approve", $this->validForm(['incident_id' => (string) $incident->id, 'report' => '第2報']))
            ->assertRedirect('/admin/reviews');

        $this->assertSame(5, Incident::query()->count());
        $this->assertSame(2, $incident->fresh()->currentRevision->revision_number);
        $this->assertSame('第2報', $incident->fresh()->currentRevision->report);
        $this->assertSame(1, IncidentRevision::query()->where('incident_id', $incident->id)->where('revision_number', 1)->count());
    }

    public function test_wrong_password_creates_nothing(): void
    {
        $review = $this->review();

        $this->post("/admin/reviews/{$review->id}/approve", $this->validForm(['password' => 'wrong']))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Incident::query()->count());
        $this->assertSame('pending_review', $review->fresh()->status);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidForms(): array
    {
        return [
            'missing company' => [['company' => ''], 'company'],
            'missing summary' => [['summary' => ''], 'summary'],
            'source not https' => [['source' => 'http://www.test-corp.example.co.jp/news/1019.html'], 'source'],
            'source not a url' => [['source' => 'javascript:alert(1)'], 'source'],
            'bad date' => [['date' => '2026-10-19'], 'date'],
            'unknown type' => [['type' => 'なにか'], 'type'],
            'unknown disclosure' => [['disclosure' => '確定'], 'disclosure'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('invalidForms')]
    public function test_invalid_input_is_rejected(array $override, string $field): void
    {
        $review = $this->review();

        $this->post("/admin/reviews/{$review->id}/approve", $this->validForm($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Incident::query()->count());
    }

    public function test_mock_and_failed_drafts_cannot_be_approved(): void
    {
        $mock = $this->review(['analyzer' => 'mock']);
        $failed = $this->review(['status' => 'analysis_failed', 'error_code' => 'ai_failed']);

        $this->get("/admin/reviews/{$mock->id}/approve")->assertStatus(409);
        $this->post("/admin/reviews/{$mock->id}/approve", $this->validForm())->assertStatus(409);
        $this->post("/admin/reviews/{$failed->id}/approve", $this->validForm())->assertStatus(409);

        $this->assertSame(0, Incident::query()->count());
    }

    public function test_an_already_decided_review_cannot_be_approved_twice(): void
    {
        $review = $this->review();
        $this->post("/admin/reviews/{$review->id}/approve", $this->validForm());

        $this->post("/admin/reviews/{$review->id}/approve", $this->validForm())->assertStatus(409);

        $this->assertSame(1, Incident::query()->count());
    }
}
