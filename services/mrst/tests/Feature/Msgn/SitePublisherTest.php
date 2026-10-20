<?php

namespace Tests\Feature\Msgn;

use App\Models\Incident;
use App\Models\IncidentRevision;
use App\Models\SiteRelease;
use App\Services\Publishing\SiteBuilder;
use App\Services\Publishing\SitePublisher;
use App\Services\Publishing\SiteUploader;
use Database\Seeders\LaunchIncidentsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSiteBuilder;
use Tests\TestCase;

class SitePublisherTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CDN_URL = 'https://cloud.example.invalid/webaccel/deleteallcache';

    private const PUBLIC_URL = 'https://www.example.test';

    private FakeSiteBuilder $builder;

    /** What the public site's release.json currently says (the fake CDN serves the last uploaded one). */
    private int $cdnStatus = 200;

    private bool $cdnServesStale = false;

    protected function setUp(): void
    {
        parent::setUp();
        app(LaunchIncidentsSeeder::class)->run();
        Storage::fake('site');
        Storage::fake('local');
        Config::set('msgn.enabled', true);
        Config::set('msgn.indexable', false);
        Config::set('msgn.public_url', self::PUBLIC_URL);
        Config::set('msgn.confirm_attempts', 3);
        Config::set('msgn.confirm_delay_seconds', 0);
        Config::set('msgn.cdn', ['api_url' => self::CDN_URL, 'domain' => 'www.example.test', 'token' => 'tok', 'secret' => 'sec']);
        $this->builder = new FakeSiteBuilder;
        $this->app->instance(SiteBuilder::class, $this->builder);
        $this->stale = null;
        Http::fake(function (Request $request) {
            if ($request->url() === self::CDN_URL) {
                return Http::response(['is_ok' => $this->cdnStatus === 200], $this->cdnStatus);
            }
            if (str_starts_with($request->url(), self::PUBLIC_URL.'/release.json')) {
                $live = $this->cdnServesStale && $this->stale !== null ? $this->stale : Storage::disk('site')->get('release.json');

                return Http::response($live ?? '{}', 200);
            }

            return Http::response('unexpected', 500);
        });
    }

    private ?string $stale;

    private function publish(): ?SiteRelease
    {
        return app(SitePublisher::class)->publish(null);
    }

    private function changeData(): void
    {
        IncidentRevision::query()->whereHas('incident', fn ($q) => $q->where('display_code', 'INC-001'))->update(['summary' => '更新後の概要']);
    }

    public function test_nothing_happens_while_disabled(): void
    {
        Config::set('msgn.enabled', false);

        $this->assertNull($this->publish());

        $this->assertSame([], $this->builder->calls);
        Http::assertNothingSent();
    }

    public function test_first_publish_uploads_purges_confirms_and_goes_live(): void
    {
        $release = $this->publish();

        $this->assertSame('live', $release->status);
        $this->assertSame(['INC-001', 'INC-002', 'INC-003', 'INC-004', 'INC-005'], $release->incident_ids);
        $this->assertSame($release->release_id, json_decode(Storage::disk('site')->get('release.json'), true)['releaseId']);
        Storage::disk('site')->assertExists(['index.html', 'robots.txt', 'favicon.svg', "assets/index-{$release->release_id}.js"]);
        Storage::disk('local')->assertExists("msgn/releases/{$release->release_id}/index.html");
        $this->assertNull($this->builder->calls[0]['previous']);
        Http::assertSent(fn (Request $r) => $r->url() === self::CDN_URL
            && $r['Site']['Domain'] === 'www.example.test'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('tok:sec')));
    }

    public function test_release_json_goes_last_and_html_just_before_it(): void
    {
        $order = app(SiteUploader::class)->plannedOrder(['release.json', 'index.html', 'assets/a.js', 'robots.txt', 'favicon.svg', 'assets/b.css']);

        $this->assertSame(['assets/a.js', 'assets/b.css', 'favicon.svg', 'robots.txt', 'index.html', 'release.json'], $order);
    }

    public function test_upload_headers_follow_the_cache_policy(): void
    {
        $uploader = app(SiteUploader::class);

        $this->assertSame(['ContentType' => 'text/html; charset=utf-8', 'CacheControl' => 'public, max-age=300'], $uploader->headersFor('index.html'));
        $this->assertSame(['ContentType' => 'application/json; charset=utf-8', 'CacheControl' => 'no-store'], $uploader->headersFor('release.json'));
        $this->assertSame(['ContentType' => 'text/javascript; charset=utf-8', 'CacheControl' => 'public, max-age=31536000, immutable'], $uploader->headersFor('assets/x.js'));
        $this->assertSame(['ContentType' => 'text/css; charset=utf-8', 'CacheControl' => 'public, max-age=31536000, immutable'], $uploader->headersFor('assets/x.css'));
        $this->assertSame(['ContentType' => 'text/plain; charset=utf-8', 'CacheControl' => 'public, max-age=300'], $uploader->headersFor('robots.txt'));
        $this->assertSame(['ContentType' => 'image/svg+xml', 'CacheControl' => 'public, max-age=86400'], $uploader->headersFor('favicon.svg'));
    }

    public function test_unchanged_data_is_skipped_without_uploading(): void
    {
        $first = $this->publish();
        Storage::disk('site')->put('index.html', 'sentinel');

        $second = $this->publish();

        $this->assertSame('skipped', $second->status);
        $this->assertSame('sentinel', Storage::disk('site')->get('index.html'));
        $this->assertSame('live', $first->fresh()->status);
    }

    public function test_the_previous_live_release_is_passed_to_the_build_guard(): void
    {
        $first = $this->publish();
        $this->changeData();

        $this->publish();

        $this->assertSame($first->incident_ids, $this->builder->calls[1]['previous']['incidentIds']);
    }

    public function test_existing_assets_are_not_uploaded_again(): void
    {
        $this->publish();
        Storage::disk('site')->put('assets/index-shared.css', 'already-there');
        $this->changeData();

        $this->publish();

        $this->assertSame('already-there', Storage::disk('site')->get('assets/index-shared.css'));
    }

    public function test_a_new_publish_supersedes_the_previous_live_release(): void
    {
        $first = $this->publish();
        $this->changeData();

        $second = $this->publish();

        $this->assertSame('live', $second->status);
        $this->assertSame('superseded', $first->fresh()->status);
    }

    public function test_a_build_failure_uploads_nothing_and_keeps_the_live_release(): void
    {
        $first = $this->publish();
        $this->changeData();
        $this->builder->fail = true;
        $before = Storage::disk('site')->get('index.html');

        $failed = $this->publish();

        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('build', $failed->error);
        $this->assertSame($before, Storage::disk('site')->get('index.html'));
        $this->assertSame('live', $first->fresh()->status);
    }

    public function test_an_unconfirmed_release_is_rolled_back_to_the_previous_live_one(): void
    {
        $first = $this->publish();
        $previousHtml = Storage::disk('site')->get('index.html');
        $previousRelease = Storage::disk('site')->get('release.json');
        $this->changeData();
        $this->stale = $previousRelease;
        $this->cdnServesStale = true;

        $failed = $this->publish();

        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('confirm', $failed->error);
        $this->assertSame($previousHtml, Storage::disk('site')->get('index.html'));
        $this->assertSame($previousRelease, Storage::disk('site')->get('release.json'));
        $this->assertSame('live', $first->fresh()->status);
        Http::assertSentCount(2 + 1 + 3 + 1); // first purge+confirm, second purge, 3 confirm attempts, restore purge
    }

    public function test_a_cdn_purge_failure_is_rolled_back(): void
    {
        $first = $this->publish();
        $previousHtml = Storage::disk('site')->get('index.html');
        $this->changeData();
        $this->cdnStatus = 500;

        $failed = $this->publish();

        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('purge', $failed->error);
        $this->assertSame($previousHtml, Storage::disk('site')->get('index.html'));
        $this->assertSame('live', $first->fresh()->status);
    }

    public function test_a_failed_first_publish_has_nothing_to_restore(): void
    {
        $this->cdnStatus = 500;

        $failed = $this->publish();

        $this->assertSame('failed', $failed->status);
        $this->assertSame(0, SiteRelease::query()->where('status', 'live')->count());
    }

    public function test_only_one_publish_runs_at_a_time(): void
    {
        $lock = Cache::lock(SitePublisher::LOCK, 60);
        $lock->get();

        $result = $this->publish();
        $lock->release();

        $this->assertSame('busy', $result->status);
        $this->assertSame([], $this->builder->calls);
    }

    public function test_a_withdrawal_is_published_and_the_guard_sees_it(): void
    {
        $this->publish();
        Incident::query()->where('display_code', 'INC-003')->sole()->update(['withdrawn_at' => now(), 'withdrawal_reason' => '訂正']);

        $release = $this->publish();

        $this->assertSame('live', $release->status);
        $this->assertSame(['INC-001', 'INC-002', 'INC-004', 'INC-005'], $release->incident_ids);
        $this->assertSame('INC-003', json_decode($this->builder->calls[1]['snapshot'], true)['withdrawals'][0]['id']);
    }
}
