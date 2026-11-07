<?php

namespace Tests\Feature\Admin;

use App\Jobs\Msic\FetchOfficialSource;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\HostResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

/**
 * An announcement often sits on a sibling host of the registered one (support., ir., corp.).
 * With an explicit check the admin registers that host for the same company and adds the URL.
 */
class RelatedHostSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PAGE = '/admin/official-announcements';

    private const NOTICE = 'https://support.alpha-corp.co.jp/hc/ja/articles/1-notice';

    private Company $company;

    private OfficialDomain $domain;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['infrastructure.admin_allowed_ips' => ['203.0.113.10'], 'crawler.official_fetch_enabled' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([
            'www.alpha-corp.co.jp' => ['93.184.216.34'], 'support.alpha-corp.co.jp' => ['93.184.216.35'],
            'www.beta-corp.co.jp' => ['93.184.216.36'], 'www.pref.fukuoka.lg.jp' => ['93.184.216.37'],
            'www.city.fukuoka.lg.jp' => ['93.184.216.38'], 'www.alpha-corp.example' => ['93.184.216.39'],
        ]));
        Queue::fake([FetchOfficialSource::class]);
        $this->company = Company::factory()->create(['name' => 'アルファ株式会社']);
        $this->domain = OfficialDomain::factory()->create(['company_id' => $this->company->id, 'host' => 'www.alpha-corp.co.jp']);
        $this->admin = AdminUser::factory()->active()->create();
        $this->actingAs($this->admin, 'admin');
    }

    public function test_a_sibling_host_is_registered_for_the_same_company_and_the_url_is_added(): void
    {
        $this->post(self::PAGE, [
            'official_domain_id' => $this->domain->id, 'url' => self::NOTICE,
            'register_related_host' => '1', 'source_checked' => '1',
        ])->assertRedirect(self::PAGE)->assertSessionHasNoErrors();

        $related = OfficialDomain::query()->where('host', 'support.alpha-corp.co.jp')->sole();
        $this->assertSame($this->company->id, $related->company_id);
        $this->assertSame($this->admin->id, $related->verified_by_admin_id);
        $this->assertStringContainsString('www.alpha-corp.co.jp', (string) $related->note);
        $candidate = OfficialLinkCandidate::query()->whereNull('source_document_id')->sole();
        $this->assertSame('support.alpha-corp.co.jp', $candidate->host);
        $this->assertSame(self::NOTICE, $candidate->url);
        Queue::assertPushed(FetchOfficialSource::class);
    }

    public function test_the_page_offers_the_related_host_check(): void
    {
        $this->get(self::PAGE)->assertOk()->assertSee('name="register_related_host"', false);
    }

    public function test_without_the_check_nothing_is_registered(): void
    {
        $this->post(self::PAGE, ['official_domain_id' => $this->domain->id, 'url' => self::NOTICE, 'source_checked' => '1'])
            ->assertSessionHasErrors(['url' => 'support.alpha-corp.co.jp は未登録です。同じ組織のサイトであることを確かめて、関連ホストとして登録するチェックを入れてください。']);

        $this->assertSame(1, OfficialDomain::query()->count());
        $this->assertSame(0, OfficialLinkCandidate::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unrelatedHosts(): array
    {
        return [
            'another company under co.jp' => ['www.alpha-corp.co.jp', 'https://www.beta-corp.co.jp/news/1.html'],
            'a different registrable domain' => ['www.alpha-corp.co.jp', 'https://www.alpha-corp.example/news/1.html'],
            'city and prefecture under lg.jp' => ['www.pref.fukuoka.lg.jp', 'https://www.city.fukuoka.lg.jp/news/1.html'],
        ];
    }

    #[DataProvider('unrelatedHosts')]
    public function test_a_host_outside_the_registered_domain_is_refused(string $registered, string $url): void
    {
        $selected = $registered === $this->domain->host
            ? $this->domain
            : OfficialDomain::factory()->create(['company_id' => $this->company->id, 'host' => $registered]);

        $this->post(self::PAGE, ['official_domain_id' => $selected->id, 'url' => $url, 'register_related_host' => '1', 'source_checked' => '1'])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, OfficialDomain::query()->where('host', parse_url($url, PHP_URL_HOST))->count());
        $this->assertSame(0, OfficialLinkCandidate::query()->count());
    }

    public function test_a_top_page_on_the_sibling_host_registers_nothing(): void
    {
        $this->post(self::PAGE, [
            'official_domain_id' => $this->domain->id, 'url' => 'https://support.alpha-corp.co.jp/',
            'register_related_host' => '1', 'source_checked' => '1',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, OfficialDomain::query()->where('host', 'support.alpha-corp.co.jp')->count());
        $this->assertSame(0, OfficialLinkCandidate::query()->count());
    }

    public function test_a_host_already_registered_to_another_company_is_refused(): void
    {
        OfficialDomain::factory()->create(['host' => 'support.alpha-corp.co.jp']);

        $this->post(self::PAGE, [
            'official_domain_id' => $this->domain->id, 'url' => self::NOTICE,
            'register_related_host' => '1', 'source_checked' => '1',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, OfficialLinkCandidate::query()->count());
    }
}
