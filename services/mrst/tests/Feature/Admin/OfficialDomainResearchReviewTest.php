<?php

namespace Tests\Feature\Admin;

use App\Jobs\Msnc\ResearchOfficialDomain;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\OfficialDomainResearch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OfficialDomainResearchFixtures as F;
use Tests\TestCase;

class OfficialDomainResearchReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['infrastructure.admin_allowed_ips' => ['127.0.0.1/32'], 'domain_research.enabled' => true, 'app.debug' => false]);
    }

    private function prepared(): OfficialDomainInvestigation
    {
        Queue::fake([ResearchOfficialDomain::class]);
        OfficialLinkCandidate::factory()->create(['host' => F::HOST, 'url' => 'https://alpha.example/', 'status' => 'pending']);
        $research = app(OfficialDomainResearch::class)->request(F::HOST);
        $research->update([
            'status' => 'completed', 'recommendation' => 'recommend', 'organization_name' => '株式会社アルファ',
            'evidence' => F::proposal()['evidence'], 'reason' => '会社概要で確認', 'final_url' => F::URL,
            'raw_proposal' => F::proposal(), 'completed_at' => now(),
        ]);

        return $research;
    }

    private function login(): AdminUser
    {
        $admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($admin);

        return $admin;
    }

    private function payload(OfficialDomainInvestigation $research): array
    {
        return [
            'host' => $research->host, 'investigation_id' => $research->id, 'decision' => 'register',
            'company_name' => '株式会社アルファ（確認済）', 'reason' => '会社概要の運営者を確認',
            'evidence_checked' => '1', 'ownership_checked' => '1', 'name_checked' => '1', 'batch_checked' => '1',
        ];
    }

    public function test_displays_six_fields_and_escaped_evidence_and_confirmation_controls(): void
    {
        $research = $this->prepared();
        $research->update(['reason' => '<script>alert(1)</script>']);
        $this->login();

        $this->get('/admin/link-candidates')
            ->assertSee('株式会社アルファ')->assertSee('登録推奨')->assertSee(F::URL)
            ->assertSee('根拠')->assertSee('最終取得URL')->assertSee('evidence_checked', false)
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertDatabaseCount('official_domains', 0);
    }

    public function test_registering_saves_human_decision_separately_and_accepts_only_pending_for_host(): void
    {
        $research = $this->prepared();
        OfficialLinkCandidate::factory()->create(['host' => F::HOST, 'status' => 'rejected']);
        OfficialLinkCandidate::factory()->create(['host' => 'beta.example', 'status' => 'pending']);
        $admin = $this->login();

        $this->post('/admin/domain-research/decision', $this->payload($research))->assertRedirect('/admin/link-candidates');

        $this->assertDatabaseHas('official_domains', ['host' => F::HOST, 'verified_by_admin_id' => $admin->id]);
        $this->assertDatabaseHas('official_domain_decisions', ['investigation_id' => $research->id, 'decision' => 'register', 'company_name' => '株式会社アルファ（確認済）']);
        $this->assertSame('株式会社アルファ', $research->fresh()->raw_proposal['organization_name']);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'accepted']);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'rejected']);
        $this->assertDatabaseHas('official_link_candidates', ['host' => 'beta.example', 'status' => 'pending']);
    }

    public function test_hold_records_reason_without_registering_or_rejecting(): void
    {
        $research = $this->prepared();
        $this->login();
        $this->post('/admin/domain-research/decision', array_replace($this->payload($research), ['decision' => 'hold', 'reason' => '企業の区別を確認中']))
            ->assertRedirect('/admin/link-candidates');

        $this->assertDatabaseHas('official_domain_decisions', ['decision' => 'hold', 'reason' => '企業の区別を確認中']);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
    }

    public static function invalidConfirmation(): array
    {
        return [
            'missing evidence confirmation' => ['evidence_checked', '0'],
            'missing owner confirmation' => ['ownership_checked', '0'],
            'missing name confirmation' => ['name_checked', '0'],
            'missing batch confirmation' => ['batch_checked', '0'],
            'missing reason' => ['reason', ''],
            'missing name' => ['company_name', ''],
        ];
    }

    #[DataProvider('invalidConfirmation')]
    public function test_missing_human_confirmation_does_not_write_anything(string $key, string $value): void
    {
        $research = $this->prepared();
        $this->login();
        $this->post('/admin/domain-research/decision', array_replace($this->payload($research), [$key => $value]))
            ->assertSessionHasErrors($key);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('official_domain_decisions', 0);
    }

    public function test_stale_or_mismatched_investigation_cannot_be_registered(): void
    {
        $old = $this->prepared();
        app(OfficialDomainResearch::class)->request(F::HOST, true);
        $this->login();
        $this->post('/admin/domain-research/decision', $this->payload($old))->assertConflict();
        $this->post('/admin/domain-research/decision', array_replace($this->payload($old), ['host' => 'beta.example']))->assertConflict();
        $this->assertDatabaseCount('official_domains', 0);
    }

    public function test_old_registration_endpoint_cannot_bypass_confirmation_for_researched_host(): void
    {
        $this->prepared();
        $this->login();
        $this->post('/admin/official-domains', ['host' => F::HOST, 'company_name' => 'Bypass'])->assertConflict();
        $this->assertDatabaseCount('official_domains', 0);
    }

    public function test_explicit_company_selection_reuses_existing_company(): void
    {
        $research = $this->prepared();
        $company = Company::factory()->create(['name' => '株式会社アルファ']);
        $this->login();
        $this->post('/admin/domain-research/decision', array_replace($this->payload($research), ['company_id' => $company->id, 'company_name' => $company->name]))
            ->assertRedirect('/admin/link-candidates');
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('official_domains', ['company_id' => $company->id]);
    }

    public function test_audit_failure_rolls_back_domain_company_and_candidate_updates(): void
    {
        $research = $this->prepared();
        $this->login();
        DB::statement("ALTER TABLE official_domain_decisions ADD CONSTRAINT fixture_no_register CHECK (decision <> 'register')");
        $this->post('/admin/domain-research/decision', $this->payload($research))->assertServerError();
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
    }

    public function test_repeated_registration_cannot_create_another_domain_or_decision(): void
    {
        $research = $this->prepared();
        $this->login();
        $this->post('/admin/domain-research/decision', $this->payload($research))->assertRedirect();
        $this->post('/admin/domain-research/decision', $this->payload($research))->assertConflict();
        $this->assertDatabaseCount('official_domains', 1);
        $this->assertDatabaseCount('official_domain_decisions', 1);
    }

    public function test_inactive_admin_and_disallowed_ip_cannot_decide(): void
    {
        $research = $this->prepared();
        Auth::guard('admin')->login(AdminUser::factory()->create(['is_active' => false]));
        $this->post('/admin/domain-research/decision', $this->payload($research))->assertForbidden();
        $this->login();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.55'])->post('/admin/domain-research/decision', $this->payload($research))->assertForbidden();
        $this->assertDatabaseCount('official_domains', 0);
    }

    private function enforceCsrf(): void
    {
        // Keep mrst_test configuration; only disable the framework's test-only CSRF bypass.
        $this->app->instance('env', 'csrf-verification');
    }

    private function reviewForm(): array
    {
        $response = $this->get('/admin/link-candidates')->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@action="'.route('admin.domain-research.decision').'"]')->item(0);
        $this->assertNotNull($form);
        $inputs = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            if ($input->getAttribute('type') !== 'checkbox' || $input->hasAttribute('checked')) {
                $inputs[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }
        $this->assertNotEmpty($inputs['_token']);

        return [$xpath, $form, $inputs];
    }

    public static function invalidCsrf(): array
    {
        return [
            'missing token without origin metadata' => [null, null],
            'wrong token without origin metadata' => ['wrong-token', null],
            'missing token from another site' => [null, 'cross-site'],
            'wrong token from another site' => ['wrong-token', 'cross-site'],
        ];
    }

    #[DataProvider('invalidCsrf')]
    public function test_registration_rejects_invalid_csrf_without_any_writes(?string $token, ?string $origin): void
    {
        $research = $this->prepared();
        $this->login();
        $this->enforceCsrf();
        $this->reviewForm();
        $data = $this->payload($research);
        if ($token !== null) {
            $data['_token'] = $token;
        }
        $headers = $origin === null ? [] : ['Sec-Fetch-Site' => $origin];

        $this->post('/admin/domain-research/decision', $data, $headers)->assertStatus(419);

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('official_domain_decisions', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
    }

    public function test_rendered_evidence_link_and_edited_name_form_register_with_valid_csrf(): void
    {
        $research = $this->prepared();
        $admin = $this->login();
        $this->enforceCsrf();
        [$xpath, $form, $inputs] = $this->reviewForm();
        $section = $form->parentNode;
        $link = $xpath->query('.//a[@href="'.F::URL.'"]', $section)->item(0);
        $this->assertNotNull($link);
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertStringContainsString('noopener', $link->getAttribute('rel'));
        $this->assertSame(F::proposal()['evidence'][0]['quote'], $xpath->query('.//blockquote', $section)->item(0)->textContent);
        $this->assertSame('株式会社アルファ', $inputs['company_name']);
        $this->assertSame(F::HOST, $inputs['host']);
        $this->assertSame(1, $xpath->query('.//button[@name="decision"][@value="register"]', $form)->length);

        $this->post('/admin/domain-research/decision', array_replace($inputs, $this->payload($research)))
            ->assertRedirect('/admin/link-candidates');

        $this->get('/admin/link-candidates')->assertSee('株式会社アルファ（確認済）');
        $this->assertDatabaseHas('companies', ['name' => '株式会社アルファ（確認済）']);
        $this->assertDatabaseHas('official_domains', ['host' => F::HOST, 'verified_by_admin_id' => $admin->id]);
        $this->assertDatabaseHas('official_domain_decisions', ['company_name' => '株式会社アルファ（確認済）', 'admin_id' => $admin->id]);
        $this->assertSame('株式会社アルファ', $research->fresh()->raw_proposal['organization_name']);
    }

    public function test_rendered_hold_form_saves_reason_without_confirmation_checks_and_redisplays_it(): void
    {
        $research = $this->prepared();
        $admin = $this->login();
        $this->enforceCsrf();
        [$xpath, $form, $inputs] = $this->reviewForm();
        $this->assertSame(1, $xpath->query('.//button[@name="decision"][@value="hold"]', $form)->length);
        $reason = '親会社との区別を確認中 <script>alert(1)</script>';

        $this->post('/admin/domain-research/decision', $inputs + ['decision' => 'hold', 'reason' => $reason])
            ->assertRedirect('/admin/link-candidates');

        $this->get('/admin/link-candidates')->assertSee('前回の人の判断：保留')
            ->assertSee($reason)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertDatabaseHas('official_domain_decisions', ['decision' => 'hold', 'reason' => $reason, 'admin_id' => $admin->id]);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
        $this->assertSame('recommend', $research->fresh()->recommendation);
    }

    public function test_hold_with_empty_reason_is_rejected_with_valid_csrf(): void
    {
        $this->prepared();
        $this->login();
        $this->enforceCsrf();
        [, , $inputs] = $this->reviewForm();

        $this->from('/admin/link-candidates')->post('/admin/domain-research/decision', $inputs + ['decision' => 'hold', 'reason' => ''])
            ->assertRedirect('/admin/link-candidates')->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('official_domain_decisions', 0);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
    }

    public function test_unauthenticated_registration_with_valid_csrf_redirects_to_login(): void
    {
        $research = $this->prepared();
        $this->enforceCsrf();
        $this->withSession(['_token' => 'fixture-session-token'])
            ->post('/admin/domain-research/decision', $this->payload($research) + ['_token' => 'fixture-session-token'])
            ->assertRedirect(route('admin.login'));
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('official_domain_decisions', 0);
    }

    public function test_same_origin_metadata_uses_framework_origin_protection_without_a_token(): void
    {
        $research = $this->prepared();
        $this->login();
        $this->enforceCsrf();

        $this->post('/admin/domain-research/decision', $this->payload($research), ['Sec-Fetch-Site' => 'same-origin'])
            ->assertRedirect('/admin/link-candidates');

        $this->assertDatabaseCount('official_domains', 1);
        $this->assertDatabaseCount('official_domain_decisions', 1);
    }

    public function test_pending_count_matches_the_candidates_accepted_on_registration(): void
    {
        $research = $this->prepared();
        OfficialLinkCandidate::factory()->create(['host' => F::HOST, 'status' => 'pending']);
        OfficialLinkCandidate::factory()->create(['host' => F::HOST, 'status' => 'rejected']);
        OfficialLinkCandidate::factory()->create(['host' => 'beta.example', 'status' => 'pending']);
        $this->login();
        $this->get('/admin/link-candidates')->assertSee('同hostの確認待ち候補 2 件');

        $this->post('/admin/domain-research/decision', $this->payload($research))->assertRedirect();

        $this->assertSame(2, OfficialLinkCandidate::query()->where('host', F::HOST)->where('status', 'accepted')->count());
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'rejected']);
        $this->assertDatabaseHas('official_link_candidates', ['host' => 'beta.example', 'status' => 'pending']);
    }

    public function test_completed_new_generation_rejects_old_evidence_without_mixing_snapshots(): void
    {
        $old = $this->prepared();
        $old->update(['pages' => [['url' => F::URL, 'sha256' => str_repeat('a', 64)]]]);
        $new = app(OfficialDomainResearch::class)->request(F::HOST, true);
        $new->update([
            'status' => 'completed', 'recommendation' => 'recommend',
            'evidence' => [['url' => 'https://alpha.example/profile', 'quote' => '世代2の根拠']],
            'pages' => [['url' => 'https://alpha.example/profile', 'sha256' => str_repeat('b', 64)]],
        ]);
        $this->login();

        $this->post('/admin/domain-research/decision', $this->payload($old))->assertConflict();

        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('official_domain_decisions', 0);
        $this->assertSame(str_repeat('a', 64), $old->fresh()->pages[0]['sha256']);
        $this->assertSame(str_repeat('b', 64), $new->fresh()->pages[0]['sha256']);
        $this->assertSame('世代2の根拠', $new->fresh()->evidence[0]['quote']);
    }

    public function test_corrected_name_reuses_only_the_explicit_company_and_keeps_ai_name(): void
    {
        $research = $this->prepared();
        $company = Company::factory()->create(['name' => '株式会社アルファ正式商号']);
        Company::factory()->create(['name' => '株式会社アルファ別法人']);
        $this->login();

        $this->post('/admin/domain-research/decision', array_replace($this->payload($research), [
            'company_id' => $company->id, 'company_name' => $company->name,
        ]))->assertRedirect();

        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseHas('official_domains', ['host' => F::HOST, 'company_id' => $company->id]);
        $this->assertDatabaseHas('official_domain_decisions', ['company_name' => $company->name, 'company_id' => $company->id]);
        $this->assertSame('株式会社アルファ', $research->fresh()->raw_proposal['organization_name']);
    }

    public function test_unconfirmed_name_is_blank_and_system_host_and_urls_are_displayed(): void
    {
        $research = $this->prepared();
        $research->update([
            'organization_name' => null, 'recommendation' => 'hold',
            'raw_proposal' => F::proposal() + ['candidate_host' => 'evil.example', 'final_url' => 'https://evil.example/'],
        ]);
        $this->login();

        $this->get('/admin/link-candidates')
            ->assertSee('組織名：未確認')->assertSee('name="company_name" value=""', false)
            ->assertSee(F::HOST)->assertSee(F::URL)->assertDontSee('evil.example');
    }
}
