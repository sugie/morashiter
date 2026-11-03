<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfficialLinkReviewTest extends TestCase
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

    private function candidate(string $host, string $path, string $status = 'pending'): OfficialLinkCandidate
    {
        $url = "https://{$host}{$path}";

        return OfficialLinkCandidate::factory()->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'host' => $host,
            'status' => $status,
        ]);
    }

    public function test_review_page_requires_an_admin(): void
    {
        $this->get('/admin/link-candidates')->assertRedirect(route('admin.login'));
    }

    public function test_pending_candidates_are_grouped_by_host(): void
    {
        $this->candidate('www.alpha-corp.example.co.jp', '/news/1.html');
        $this->candidate('www.alpha-corp.example.co.jp', '/news/2.html');
        $this->candidate('www.beta-corp.example.jp', '/info/notice.pdf');
        $this->candidate('www.gamma-corp.example.jp', '/old.html', 'rejected');
        $this->loginAdmin();

        $response = $this->get('/admin/link-candidates');

        $response->assertOk()
            ->assertSeeInOrder(['www.alpha-corp.example.co.jp', '2', 'www.beta-corp.example.jp', '1'])
            ->assertSee('https://www.alpha-corp.example.co.jp/news/1.html')
            ->assertDontSee('www.gamma-corp.example.jp');
    }

    public function test_company_name_suggestions_include_link_labels_and_previously_registered_names_without_registering_them(): void
    {
        Company::factory()->create(['name' => '登録済み株式会社']);
        $candidate = $this->candidate('alpha.example.jp', '/');
        $candidate->update(['anchor_text' => 'アルファ株式会社']);
        $candidate->sourceDocument->update(['title' => 'アルファの漏えい事件']);
        $this->candidate('alpha.example.jp', '/news')->update(['anchor_text' => 'アルファ株式会社']);
        $this->candidate('beta.example.jp', '/')->update(['anchor_text' => 'ベータ株式会社']);
        $this->loginAdmin();

        $this->get('/admin/link-candidates')
            ->assertViewHas('companySuggestions', fn ($suggestions): bool => $suggestions['alpha.example.jp'] === ['アルファ株式会社', '登録済み株式会社']
                && $suggestions['beta.example.jp'] === ['ベータ株式会社', '登録済み株式会社'])
            ->assertSee('<option value="アルファ株式会社">', false)
            ->assertSee('<option value="登録済み株式会社">', false)
            ->assertSee('アルファの漏えい事件')->assertSee('入力候補');
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertSame('pending', $candidate->fresh()->status);
    }

    public function test_company_suggestions_escape_html_and_ignore_empty_link_labels(): void
    {
        $label = '<script>alert(1)</script>';
        $this->candidate('alpha.example.jp', '/')->update(['anchor_text' => $label]);
        $this->candidate('alpha.example.jp', '/empty')->update(['anchor_text' => '  ']);
        $this->loginAdmin();

        $this->get('/admin/link-candidates')
            ->assertViewHas('companySuggestions', fn ($suggestions): bool => $suggestions['alpha.example.jp'] === [$label])
            ->assertSee($label)->assertDontSee($label, false);
    }

    public function test_failed_and_missing_official_links_remain_visible_after_domain_confirmation(): void
    {
        $this->candidate('failed.example.jp', '/', 'failed')->update(['error_code' => 'http_non_200']);
        $this->candidate('missing.example.jp', '/', 'fetched')->update(['error_code' => 'no_incident_link']);
        $this->candidate('success.example.jp', '/', 'fetched');
        $this->candidate('rejected.example.jp', '/', 'rejected');
        $this->loginAdmin();

        $this->get('/admin/link-candidates')->assertSee('id="retrieval-issues"', false)
            ->assertSee('failed.example.jp')->assertSee('missing.example.jp')
            ->assertSee('取得失敗')->assertSee('公式発表が見つからない')
            ->assertDontSee('success.example.jp')->assertDontSee('rejected.example.jp');
    }

    public function test_validation_preserves_the_company_name_only_for_the_submitted_host(): void
    {
        $this->candidate('alpha.example.jp', '/');
        $this->candidate('beta.example.jp', '/');
        $this->loginAdmin();

        $html = $this->withSession(['_old_input' => ['host' => 'alpha.example.jp', 'company_name' => '入力途中の株式会社']])
            ->get('/admin/link-candidates')->assertSee('value="入力途中の株式会社"', false)->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $this->assertSame('入力途中の株式会社', $xpath->evaluate('string(//form[input[@name="host" and @value="alpha.example.jp"]]/input[@name="company_name"]/@value)'));
        $this->assertSame('', $xpath->evaluate('string(//form[input[@name="host" and @value="beta.example.jp"]]/input[@name="company_name"]/@value)'));
        foreach ($xpath->query('//input[@name="company_name"]') as $input) {
            $this->assertSame(1, $xpath->query('//datalist[@id="'.$input->getAttribute('list').'"]')->length);
        }
    }

    public function test_retrieval_issues_can_be_paged_beyond_the_first_25(): void
    {
        $oldest = $this->candidate('oldest.example.jp', '/', 'failed');
        OfficialLinkCandidate::factory()->count(25)->create(['status' => 'failed']);
        $this->loginAdmin();

        $this->get('/admin/link-candidates?issues_page=2')->assertSee($oldest->url)
            ->assertViewHas('retrievalIssues', fn ($issues): bool => $issues->total() === 26 && $issues->count() === 1);
    }

    public function test_registering_a_host_records_company_domain_and_approver_and_accepts_its_candidates(): void
    {
        $first = $this->candidate('www.alpha-corp.example.co.jp', '/news/1.html');
        $second = $this->candidate('www.alpha-corp.example.co.jp', '/news/2.html');
        $other = $this->candidate('www.beta-corp.example.jp', '/info/notice.pdf');
        $this->loginAdmin();

        $this->post('/admin/official-domains', [
            'host' => 'www.alpha-corp.example.co.jp',
            'company_name' => 'アルファ株式会社',
        ])->assertRedirect('/admin/link-candidates');

        $domain = OfficialDomain::query()->sole();
        $this->assertSame('www.alpha-corp.example.co.jp', $domain->host);
        $this->assertSame('アルファ株式会社', $domain->company->name);
        $this->assertSame($this->admin->id, $domain->verified_by_admin_id);
        $this->assertNotNull($domain->verified_at);
        $this->assertSame('accepted', $first->fresh()->status);
        $this->assertSame('accepted', $second->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_registering_a_second_host_for_an_existing_company_reuses_it(): void
    {
        Company::factory()->create(['name' => 'アルファ株式会社']);
        $this->loginAdmin();

        $this->post('/admin/official-domains', ['host' => 'ir.alpha-corp.example.co.jp', 'company_name' => 'アルファ株式会社']);

        $this->assertSame(1, Company::query()->count());
        $this->assertSame(1, OfficialDomain::query()->count());
    }

    public function test_host_is_normalised_to_lowercase(): void
    {
        $this->loginAdmin();

        $this->post('/admin/official-domains', ['host' => 'WWW.Alpha-Corp.Example.co.jp', 'company_name' => 'アルファ株式会社']);

        $this->assertSame('www.alpha-corp.example.co.jp', OfficialDomain::query()->sole()->host);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'url instead of host' => [['host' => 'https://www.alpha.example.jp/', 'company_name' => 'A社']],
            'host with path' => [['host' => 'www.alpha.example.jp/news', 'company_name' => 'A社']],
            'ip address' => [['host' => '192.168.0.10', 'company_name' => 'A社']],
            'single label' => [['host' => 'localhost', 'company_name' => 'A社']],
            'missing company' => [['host' => 'www.alpha.example.jp', 'company_name' => '']],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_is_rejected(array $payload): void
    {
        $this->loginAdmin();

        $this->post('/admin/official-domains', $payload)->assertSessionHasErrors();

        $this->assertSame(0, OfficialDomain::query()->count());
    }

    public function test_a_host_cannot_be_registered_twice(): void
    {
        $this->loginAdmin();
        $this->post('/admin/official-domains', ['host' => 'www.alpha.example.jp', 'company_name' => 'A社']);

        $this->post('/admin/official-domains', ['host' => 'www.alpha.example.jp', 'company_name' => 'B社'])
            ->assertSessionHasErrors('host');

        $this->assertSame(1, OfficialDomain::query()->count());
    }

    public function test_rejecting_a_host_marks_only_its_pending_candidates(): void
    {
        $pending = $this->candidate('www.ad-network.example.com', '/click');
        $accepted = $this->candidate('www.ad-network.example.com', '/other', 'accepted');
        $other = $this->candidate('www.alpha.example.jp', '/news/1.html');
        $this->loginAdmin();

        $this->post('/admin/link-candidates/reject-host', ['host' => 'www.ad-network.example.com'])
            ->assertRedirect('/admin/link-candidates');

        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_inactive_admin_cannot_register_domains(): void
    {
        Auth::guard('admin')->login(AdminUser::factory()->create(['is_active' => false]));

        $this->post('/admin/official-domains', ['host' => 'www.alpha.example.jp', 'company_name' => 'A社'])->assertForbidden();

        $this->assertSame(0, OfficialDomain::query()->count());
    }
}
