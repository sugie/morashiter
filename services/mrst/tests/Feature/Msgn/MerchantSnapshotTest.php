<?php

namespace Tests\Feature\Msgn;

use App\Models\Company;
use App\Models\Incident;
use App\Models\MerchantDomain;
use App\Services\Publishing\PublicSnapshotBuilder;
use App\Services\Publishing\SiteUploader;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The snapshot carries the shopping hosts the extension may match ("merchants"). The static build turns
 * it into api/v1/index-meta.json and api/v1/buckets/<sha256 prefix>.json. Only enabled hosts of a company
 * with at least one published (approved, not withdrawn) incident are listed.
 */
class MerchantSnapshotTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        app(LaunchIncidentsSeeder::class)->run();
        $this->alpha = Company::factory()->create(['name' => 'アルファ通販株式会社']);
        Incident::query()->whereIn('display_code', ['INC-003', 'INC-001'])->update(['company_id' => $this->alpha->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return json_decode(app(PublicSnapshotBuilder::class)->json(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function domain(string $host, ?Company $company = null, array $overrides = []): MerchantDomain
    {
        return MerchantDomain::factory()->create($overrides + [
            'host' => $host,
            'company_id' => ($company ?? $this->alpha)->id,
            'evidence_url' => 'https://www.alpha-corp.example.co.jp/company/shops.html',
            'note' => '社内メモ：担当者に確認済み',
        ]);
    }

    public function test_hosts_of_a_company_with_published_incidents_are_listed_in_a_fixed_order(): void
    {
        $this->domain('www.alpha-shop.co.jp');
        $this->domain('alpha-shop.co.jp');
        $this->domain('outlet.alpha-books.jp');

        $merchants = $this->snapshot()['merchants'];

        $this->assertSame(2, $merchants['prefix_length']);
        $this->assertSame([
            ['registrable_domain' => 'alpha-books.jp', 'host' => 'outlet.alpha-books.jp', 'company' => 'アルファ通販株式会社', 'incident_ids' => ['INC-001', 'INC-003']],
            ['registrable_domain' => 'alpha-shop.co.jp', 'host' => 'alpha-shop.co.jp', 'company' => 'アルファ通販株式会社', 'incident_ids' => ['INC-001', 'INC-003']],
            ['registrable_domain' => 'alpha-shop.co.jp', 'host' => 'www.alpha-shop.co.jp', 'company' => 'アルファ通販株式会社', 'incident_ids' => ['INC-001', 'INC-003']],
        ], $merchants['domains']);
    }

    public function test_disabled_hosts_and_companies_without_published_incidents_are_left_out(): void
    {
        $this->domain('www.alpha-shop.co.jp');
        $this->domain('old.alpha-shop.co.jp', overrides: ['disabled_at' => now()]);
        $this->domain('www.beta-books.co.jp', Company::factory()->create(['name' => 'ベータ書房株式会社']));

        $hosts = array_column($this->snapshot()['merchants']['domains'], 'host');

        $this->assertSame(['www.alpha-shop.co.jp'], $hosts);
    }

    public function test_withdrawn_incidents_are_not_listed_and_a_company_left_with_none_drops_out(): void
    {
        $this->domain('www.alpha-shop.co.jp');
        Incident::query()->where('display_code', 'INC-001')->update(['withdrawn_at' => now(), 'withdrawal_reason' => '重複']);

        $this->assertSame(['INC-003'], $this->snapshot()['merchants']['domains'][0]['incident_ids']);

        Incident::query()->where('display_code', 'INC-003')->update(['withdrawn_at' => now(), 'withdrawal_reason' => '重複']);
        $this->assertSame([], $this->snapshot()['merchants']['domains']);
    }

    public function test_no_internal_merchant_fields_reach_the_snapshot(): void
    {
        $this->domain('www.alpha-shop.co.jp');

        $raw = app(PublicSnapshotBuilder::class)->json();

        $this->assertSame(['registrable_domain', 'host', 'company', 'incident_ids'], array_keys($this->snapshot()['merchants']['domains'][0]));
        foreach (['evidence_url', 'alpha-corp.example.co.jp/company', '社内メモ', 'verified_by', 'disabled_at'] as $internal) {
            $this->assertStringNotContainsString($internal, $raw);
        }
    }

    public function test_registering_a_host_changes_the_hash_so_the_publish_is_not_skipped(): void
    {
        $before = app(PublicSnapshotBuilder::class)->sha256();
        $this->domain('www.alpha-shop.co.jp');

        $this->assertNotSame($before, app(PublicSnapshotBuilder::class)->sha256());
    }

    public function test_an_empty_list_is_still_written(): void
    {
        $this->assertSame(['prefix_length' => 2, 'domains' => []], $this->snapshot()['merchants']);
    }

    public function test_the_prefix_length_comes_from_the_config_and_stays_between_2_and_4(): void
    {
        config()->set('msgn.merchant_prefix_length', 3);
        $this->assertSame(3, $this->snapshot()['merchants']['prefix_length']);

        config()->set('msgn.merchant_prefix_length', 9);
        $this->assertSame(2, $this->snapshot()['merchants']['prefix_length']);
    }

    public function test_api_files_are_uploaded_as_json_before_the_pages(): void
    {
        $uploader = app(SiteUploader::class);

        $this->assertSame(['ContentType' => 'application/json; charset=utf-8', 'CacheControl' => 'public, max-age=300'], $uploader->headersFor('api/v1/index-meta.json'));
        $this->assertSame(['ContentType' => 'application/json; charset=utf-8', 'CacheControl' => 'public, max-age=600'], $uploader->headersFor('api/v1/buckets/3f.json'));
        $this->assertSame(
            ['assets/a.js', 'api/v1/buckets/00.json', 'api/v1/index-meta.json', 'robots.txt', 'index.html', 'release.json'],
            $uploader->plannedOrder(['release.json', 'index.html', 'api/v1/index-meta.json', 'robots.txt', 'api/v1/buckets/00.json', 'assets/a.js']),
        );
    }
}
