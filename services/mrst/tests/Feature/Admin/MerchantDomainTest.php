<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\MerchantDomain;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shopping-site hosts (where people buy) are registered separately from official_domains (where a company
 * publishes notices). The extension's bucketed JSON is built from these rows.
 */
class MerchantDomainTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PAGE = '/admin/merchant-domains';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->company = Company::factory()->create(['name' => 'アルファ通販株式会社']);
    }

    private function login(bool $active = true): AdminUser
    {
        $admin = $active ? AdminUser::factory()->active()->create() : AdminUser::factory()->create(['is_active' => false]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /**
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'host' => 'www.alpha-shop.co.jp',
            'company_id' => (string) $this->company->id,
            'evidence_url' => 'https://www.alpha-corp.co.jp/company/shops.html',
            'note' => '会社概要の「運営サイト」に記載',
        ];
    }

    public function test_page_requires_an_admin(): void
    {
        $this->get(self::PAGE)->assertRedirect(route('admin.login'));
    }

    public function test_page_is_closed_to_other_ips(): void
    {
        $this->login();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get(self::PAGE)->assertForbidden();
    }

    public function test_page_lists_domains_and_offers_registered_companies(): void
    {
        $other = Company::factory()->create(['name' => 'ベータ書房株式会社']);
        MerchantDomain::factory()->create(['host' => 'shop.beta-books.co.jp', 'company_id' => $other->id]);
        $this->login();

        $response = $this->get(self::PAGE)->assertOk()
            ->assertSee('shop.beta-books.co.jp')->assertSee('ベータ書房株式会社')
            ->assertSee('name="host"', false)->assertSee('name="evidence_url"', false);
        $this->assertStringContainsString('<option value="'.$this->company->id.'"', $response->getContent());
        $this->assertStringContainsString('<option value="'.$other->id.'"', $response->getContent());
    }

    public function test_an_admin_registers_a_shopping_host_for_a_company(): void
    {
        $admin = $this->login();

        $this->post(self::PAGE, $this->form(['host' => '  WWW.Alpha-Shop.co.jp. ']))
            ->assertRedirect(self::PAGE)->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $domain = MerchantDomain::query()->sole();
        $this->assertSame('www.alpha-shop.co.jp', $domain->host);
        $this->assertSame('alpha-shop.co.jp', $domain->registrable_domain);
        $this->assertSame($this->company->id, $domain->company_id);
        $this->assertSame('https://www.alpha-corp.co.jp/company/shops.html', $domain->evidence_url);
        $this->assertSame('会社概要の「運営サイト」に記載', $domain->note);
        $this->assertSame($admin->id, $domain->verified_by_admin_id);
        $this->assertNotNull($domain->verified_at);
        $this->assertNull($domain->disabled_at);
    }

    public function test_an_internationalised_host_is_stored_as_punycode(): void
    {
        $this->login();

        $this->post(self::PAGE, $this->form(['host' => 'ショップ.example.jp']))->assertSessionHasNoErrors();

        $domain = MerchantDomain::query()->sole();
        $this->assertSame(idn_to_ascii('ショップ.example.jp', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46), $domain->host);
        $this->assertStringStartsWith('xn--', $domain->host);
        $this->assertSame('example.jp', $domain->registrable_domain);
    }

    public function test_a_host_of_253_characters_is_accepted(): void
    {
        $this->login();
        $host = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 58).'.jp';
        $this->assertSame(253, strlen($host));

        $this->post(self::PAGE, $this->form(['host' => $host]))->assertSessionHasNoErrors();

        $this->assertSame($host, MerchantDomain::query()->sole()->host);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHosts(): array
    {
        return [
            'empty' => [''],
            'an IPv4 address' => ['203.0.113.5'],
            'an IPv6 address' => ['[2001:db8::1]'],
            'localhost' => ['localhost'],
            'a localhost subdomain' => ['shop.localhost'],
            'with a scheme' => ['https://www.alpha-shop.co.jp'],
            'with a path' => ['www.alpha-shop.co.jp/items'],
            'with a port' => ['www.alpha-shop.co.jp:8080'],
            'a public suffix only' => ['co.jp'],
            'a top-level domain only' => ['com'],
            'a wildcard' => ['*.alpha-shop.co.jp'],
            'too long (254 characters)' => [str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 59).'.jp'],
        ];
    }

    #[DataProvider('invalidHosts')]
    public function test_invalid_hosts_are_refused(string $host): void
    {
        $this->login();

        $this->post(self::PAGE, $this->form(['host' => $host]))->assertSessionHasErrors('host');

        $this->assertSame(0, MerchantDomain::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidOtherFields(): array
    {
        return [
            'evidence over http' => ['evidence_url', 'http://www.alpha-corp.co.jp/company/shops.html'],
            'evidence missing' => ['evidence_url', ''],
            'evidence not a URL' => ['evidence_url', '会社概要を見た'],
            'unknown company' => ['company_id', '999999'],
            'company missing' => ['company_id', ''],
        ];
    }

    #[DataProvider('invalidOtherFields')]
    public function test_evidence_and_company_are_required(string $field, string $value): void
    {
        $this->login();

        $this->post(self::PAGE, $this->form([$field => $value]))->assertSessionHasErrors($field);

        $this->assertSame(0, MerchantDomain::query()->count());
    }

    public function test_a_host_registered_already_is_refused_even_for_another_company(): void
    {
        $other = Company::factory()->create();
        MerchantDomain::factory()->create(['host' => 'www.alpha-shop.co.jp', 'company_id' => $other->id]);
        $this->login();

        $this->post(self::PAGE, $this->form())->assertSessionHasErrors('host');

        $this->assertSame($other->id, MerchantDomain::query()->sole()->company_id);
    }

    public function test_disabling_keeps_the_row_and_shows_it_as_disabled(): void
    {
        $domain = MerchantDomain::factory()->create(['host' => 'www.alpha-shop.co.jp', 'company_id' => $this->company->id]);
        $this->login();

        $this->post(route('admin.merchant-domains.disable', $domain))->assertRedirect(self::PAGE);

        $this->assertNotNull($domain->fresh()->disabled_at);
        $this->assertSame(1, MerchantDomain::query()->count());
        $this->get(self::PAGE)->assertSee('www.alpha-shop.co.jp')->assertSee('無効');
    }

    public function test_only_enabled_domains_are_active(): void
    {
        MerchantDomain::factory()->create(['host' => 'www.alpha-shop.co.jp', 'company_id' => $this->company->id]);
        MerchantDomain::factory()->create(['host' => 'old.alpha-shop.co.jp', 'company_id' => $this->company->id, 'disabled_at' => now()]);

        $this->assertSame(['www.alpha-shop.co.jp'], MerchantDomain::query()->active()->pluck('host')->all());
    }

    public function test_inactive_admin_cannot_register_or_disable(): void
    {
        $domain = MerchantDomain::factory()->create(['company_id' => $this->company->id]);
        $this->login(active: false);

        $this->post(self::PAGE, $this->form(['host' => 'new.alpha-shop.co.jp']))->assertForbidden();
        $this->post(route('admin.merchant-domains.disable', $domain))->assertForbidden();
        $this->assertNull($domain->fresh()->disabled_at);
    }

    public function test_dashboard_links_to_the_page(): void
    {
        $this->login();

        $this->get('/admin')->assertOk()->assertSee(route('admin.merchant-domains.index'), false);
    }
}
