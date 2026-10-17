<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ArticlesIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('infrastructure.admin_allowed_ips', ['203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
    }

    private function makeDocument(array $sourceOverrides = [], array $docOverrides = []): SourceDocument
    {
        $source = CrawlSource::factory()->create($sourceOverrides);
        $run = CrawlRun::factory()->for($source, 'source')->create();

        return SourceDocument::factory()->for($source, 'source')->create(array_merge([
            'crawl_run_id' => $run->id,
        ], $docOverrides));
    }

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $this->get('/admin/articles')->assertRedirect(route('admin.login'));
    }

    public function test_inactive_admin_cannot_see_articles(): void
    {
        $admin = AdminUser::factory()->create(['is_active' => false]);
        Auth::guard('admin')->login($admin);

        $this->get('/admin/articles')->assertForbidden();
    }

    public function test_active_admin_sees_persisted_articles(): void
    {
        $this->makeDocument(['name' => 'Alpha News'], [
            'title'        => 'アルファ事件：情報漏えいを発表',
            'canonical_url'=> 'http://fixture-news/incidents/alpha.html',
            'published_on' => '2026-10-01',
        ]);
        $this->makeDocument(['name' => 'Beta News'], [
            'title'        => 'ベータ事件：サーバー停止',
            'canonical_url'=> 'http://fixture-news/incidents/beta.html',
            'published_on' => '2026-10-02',
        ]);
        $admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($admin);

        $response = $this->get('/admin/articles');

        $response->assertOk()
            ->assertSeeText('記事一覧')
            ->assertSeeText('アルファ事件：情報漏えいを発表')
            ->assertSeeText('ベータ事件：サーバー停止')
            ->assertSeeText('Alpha News')
            ->assertSeeText('Beta News');
    }

    public function test_source_id_filter_restricts_rows(): void
    {
        $alpha = $this->makeDocument(['name' => 'Alpha News'], ['title' => 'Alpha only']);
        $beta = $this->makeDocument(['name' => 'Beta News'], ['title' => 'Beta only']);
        $admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($admin);

        $response = $this->get('/admin/articles?source_id='.$alpha->source_id);

        $response->assertOk()
            ->assertSeeText('Alpha only')
            ->assertDontSeeText('Beta only');
    }

    public function test_pagination_shows_26th_item_on_second_page(): void
    {
        $source = CrawlSource::factory()->create();
        $run = CrawlRun::factory()->for($source, 'source')->create();
        for ($i = 1; $i <= 26; $i++) {
            SourceDocument::factory()->for($source, 'source')->create([
                'crawl_run_id'  => $run->id,
                'title'         => sprintf('タイトル%03d', $i),
                'url_hash'      => hash('sha256', 'url-'.$i),
                'content_sha256'=> hash('sha256', 'content-'.$i),
                'fetched_at'    => now()->subMinutes($i),
            ]);
        }
        $admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($admin);

        $firstPage = $this->get('/admin/articles');
        $firstPage->assertSeeText('タイトル001')
                  ->assertDontSeeText('タイトル026');

        $secondPage = $this->get('/admin/articles?page=2');
        $secondPage->assertSeeText('タイトル026');
    }

    public function test_private_evidence_key_is_not_leaked_to_the_page(): void
    {
        $this->makeDocument([], ['private_object_key' => 'msnc/evidence/secret-leak.json']);
        $admin = AdminUser::factory()->active()->create();
        Auth::guard('admin')->login($admin);

        $this->get('/admin/articles')
            ->assertOk()
            ->assertDontSeeText('msnc/evidence/secret-leak.json');
    }
}
