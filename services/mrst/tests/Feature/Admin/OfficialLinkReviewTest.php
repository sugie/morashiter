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
