<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Incident;
use App\Models\IncidentReview;
use App\Models\IncidentRevision;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Every incident points at the company that published it (incidents.company_id), so a shopping host
 * (merchant_domains -> company) can find its incidents. Approval fills it from the official source;
 * older rows are filled by a command, and anything untraceable is chosen on the correction form.
 */
class IncidentCompanyLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        Auth::guard('admin')->login(AdminUser::factory()->active()->create());
        $this->company = Company::factory()->create(['name' => 'アルファ通販株式会社']);
    }

    private function review(?Company $company = null): IncidentReview
    {
        $domain = OfficialDomain::factory()->create(['company_id' => ($company ?? $this->company)->id]);
        $document = OfficialDocument::factory()->create(['official_domain_id' => $domain->id]);

        return IncidentReview::query()->create([
            'official_document_id' => $document->id,
            'status' => 'pending_review',
            'analyzer' => 'sakura',
            'attempts' => 1,
            'analysis' => ['organization' => 'アルファ通販株式会社', 'incident_title' => '不正アクセス', 'disclosed_at' => '2026-10-19'],
            'analyzed_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function approvalForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'アルファ通販サイト',
            'company' => 'アルファ通販株式会社',
            'type' => '不正アクセス',
            'disclosure' => '漏えいの可能性',
            'report' => '',
            'brief' => '通販サイトへの不正アクセス',
            'quantity' => '約1,200件',
            'scale' => '約1,200件',
            'data_type' => '氏名・メールアドレス',
            'date' => '2026/10/19',
            'status' => '調査中',
            'summary' => '通販サイトへの不正アクセスにより、個人情報が流出した可能性を公表しました。',
            'target' => '通販サイトの利用者。',
            'action' => '会社を装う連絡に注意してください。',
            'response' => '調査中。',
            'source' => 'https://www.alpha-corp.example.co.jp/news/1019.html',
            'contact' => 'https://www.alpha-corp.example.co.jp/contact/',
        ];
    }

    private function approveNew(?Company $company = null): Incident
    {
        $review = $this->review($company);
        $this->post("/admin/reviews/{$review->id}/approve", $this->approvalForm())->assertSessionHasNoErrors();

        return IncidentRevision::query()->where('incident_review_id', $review->id)->sole()->incident;
    }

    public function test_approving_a_new_incident_links_the_company_of_its_official_source(): void
    {
        $incident = $this->approveNew();

        $this->assertSame($this->company->id, $incident->fresh()->company_id);
        $this->assertSame('アルファ通販株式会社', $incident->fresh()->company->name);
    }

    public function test_a_follow_up_fills_a_missing_company_but_never_replaces_one(): void
    {
        $incident = $this->approveNew();
        $incident->update(['company_id' => null]);
        $followUp = $this->review();
        $this->post("/admin/reviews/{$followUp->id}/approve", $this->approvalForm(['incident_id' => (string) $incident->id]))->assertSessionHasNoErrors();
        $this->assertSame($this->company->id, $incident->fresh()->company_id);

        $other = Company::factory()->create(['name' => 'ベータ書房株式会社']);
        $fromOther = $this->review($other);
        $this->post("/admin/reviews/{$fromOther->id}/approve", $this->approvalForm(['incident_id' => (string) $incident->id]))->assertSessionHasNoErrors();
        $this->assertSame($this->company->id, $incident->fresh()->company_id, 'An existing company is kept.');
    }

    public function test_the_command_only_reports_by_default(): void
    {
        $incident = $this->approveNew();
        $incident->update(['company_id' => null]);

        $this->artisan('incidents:link-companies')
            ->expectsOutputToContain($incident->display_code)
            ->expectsOutputToContain('アルファ通販株式会社')
            ->expectsOutputToContain('--apply')
            ->assertSuccessful();

        $this->assertNull($incident->fresh()->company_id);
    }

    public function test_the_command_with_apply_fills_traceable_incidents_including_withdrawn_ones(): void
    {
        $open = $this->approveNew();
        $withdrawn = $this->approveNew();
        Incident::query()->whereKey([$open->id, $withdrawn->id])->update(['company_id' => null]);
        $withdrawn->update(['withdrawn_at' => now(), 'withdrawal_reason' => '別の事件と重複']);

        $this->artisan('incidents:link-companies', ['--apply' => true])->assertSuccessful();

        $this->assertSame($this->company->id, $open->fresh()->company_id);
        $this->assertSame($this->company->id, $withdrawn->fresh()->company_id);
    }

    public function test_the_command_keeps_existing_links_and_lists_untraceable_incidents(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        $manual = Incident::query()->where('display_code', 'INC-002')->sole();
        $linked = $this->approveNew();
        $other = Company::factory()->create();
        $linked->update(['company_id' => $other->id]);

        $this->artisan('incidents:link-companies', ['--apply' => true])
            ->expectsOutputToContain('INC-002')
            ->assertSuccessful();

        $this->assertNull($manual->fresh()->company_id, 'A hand-entered incident has no official source to follow.');
        $this->assertSame($other->id, $linked->fresh()->company_id);
    }

    public function test_the_correction_form_offers_the_company_and_saves_the_choice(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        $incident = Incident::query()->where('display_code', 'INC-002')->sole();

        $html = $this->get("/admin/incidents/{$incident->id}/correct")->assertOk()->getContent();
        $this->assertStringContainsString('name="company_id"', $html);
        $this->assertStringContainsString('<option value="'.$this->company->id.'"', $html);

        $this->post("/admin/incidents/{$incident->id}/correct", $this->correction($incident, ['company_id' => (string) $this->company->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->company->id, $incident->fresh()->company_id);
    }

    public function test_leaving_the_company_empty_keeps_it_and_an_unknown_company_is_refused(): void
    {
        app(LaunchIncidentsSeeder::class)->run();
        $incident = Incident::query()->where('display_code', 'INC-002')->sole();
        $incident->update(['company_id' => $this->company->id]);

        $this->post("/admin/incidents/{$incident->id}/correct", $this->correction($incident, ['company_id' => '']))->assertSessionHasNoErrors();
        $this->assertSame($this->company->id, $incident->fresh()->company_id);

        $incident->refresh();
        $this->post("/admin/incidents/{$incident->id}/correct", $this->correction($incident, ['company_id' => '999999']))->assertSessionHasErrors('company_id');
        $this->assertSame($this->company->id, $incident->fresh()->company_id);
    }

    /**
     * @return array<string, string>
     */
    private function correction(Incident $incident, array $overrides = []): array
    {
        $revision = $incident->fresh()->currentRevision;
        $payload = ['base_revision' => (string) $revision->revision_number];
        foreach (IncidentRevision::PUBLIC_FIELDS as $siteKey => $column) {
            $payload[$siteKey === 'dataType' ? 'data_type' : $siteKey] = (string) $revision->{$column};
        }

        return $overrides + $payload;
    }
}
