<?php

namespace Tests\Feature\Msnc;

use App\Jobs\Msnc\ResearchOfficialDomain;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\OfficialDomain;
use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\HostRateLimiter;
use App\Services\Crawling\HostResolver;
use App\Services\Crawling\OfficialDomainResearch;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryHostResolver;
use Tests\Support\OfficialDomainResearchFixtures as F;
use Tests\TestCase;

class OfficialDomainResearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'domain_research.enabled' => true, 'ai.provider' => 'sakura',
            'ai.sakura.base_url' => 'https://ai.example/v1', 'ai.sakura.model' => 'fixture-model',
            'ai.sakura.api_key' => 'fixture-token', 'domain_research.max_pages' => 3,
            'domain_research.max_attempts' => 2, 'domain_research.daily_limit' => 10,
            'crawler.host_rate.min_interval_ms' => 0,
        ]);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver(['alpha.example' => ['93.184.216.34']]));
    }

    private function candidate(): OfficialLinkCandidate
    {
        return OfficialLinkCandidate::factory()->create(['host' => F::HOST, 'url' => 'https://alpha.example/', 'status' => 'pending']);
    }

    private function fakePages(array $ai = [], string $html = '', mixed $aiResponse = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://alpha.example/' => Http::response($html ?: F::home(), 200, ['Content-Type' => 'text/html']),
            'https://alpha.example/about' => Http::response(F::about(), 200, ['Content-Type' => 'text/html']),
            'https://alpha.example/profile' => Http::response(F::about(), 200, ['Content-Type' => 'text/html']),
            'https://ai.example/v1/chat/completions' => $aiResponse ?? Http::response($ai ?: F::response()),
        ]);
    }

    private function start(): OfficialDomainInvestigation
    {
        Queue::fake([ResearchOfficialDomain::class]);
        $this->candidate();

        return app(OfficialDomainResearch::class)->request(F::HOST);
    }

    public function test_research_records_evidence_but_never_registers_a_domain(): void
    {
        Storage::fake('local');
        $this->fakePages();
        $research = $this->start();

        (new ResearchOfficialDomain($research->id))->handle(app(OfficialDomainResearch::class));

        $research->refresh();
        $this->assertSame('completed', $research->status);
        $this->assertSame('recommend', $research->recommendation);
        $this->assertSame('株式会社アルファ', $research->organization_name);
        $this->assertSame(F::URL, $research->evidence[0]['url']);
        $this->assertSame(F::HOST, $research->host);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
        Storage::disk('local')->assertExists($research->snapshot_path);
        Http::assertSentCount(4);
        Queue::assertPushed(ResearchOfficialDomain::class, 1);
    }

    public function test_unknown_host_is_queued_on_candidate_creation_but_known_host_is_not(): void
    {
        Queue::fake([ResearchOfficialDomain::class]);
        $this->candidate();
        $this->candidate();
        OfficialDomain::factory()->create(['host' => 'known.example']);
        OfficialLinkCandidate::factory()->create(['host' => 'known.example', 'url' => 'https://known.example/', 'status' => 'accepted']);

        $this->assertDatabaseCount('official_domain_investigations', 1);
        Queue::assertPushed(ResearchOfficialDomain::class, 1);
    }

    public function test_logo_only_page_is_held_without_calling_ai(): void
    {
        Storage::fake('local');
        $this->fakePages(html: '<html><body>株式会社アルファ</body></html>');
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('hold', $research->fresh()->recommendation);
        $this->assertSame('no_overview', $research->fresh()->error_code);
        Http::assertSentCount(1);
    }

    public function test_cross_host_redirect_is_recorded_without_following_it(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['https://alpha.example/' => Http::response('', 301, ['Location' => 'https://group.example/'])]);
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);

        $research->refresh();
        $this->assertSame('hold', $research->recommendation);
        $this->assertSame('https://group.example/', $research->redirect_url);
        $this->assertSame('https://alpha.example/', $research->final_url);
        Http::assertSentCount(1);
    }

    public static function privateAddresses(): array
    {
        return [['127.0.0.1'], ['169.254.169.254'], ['10.0.0.1']];
    }

    #[DataProvider('privateAddresses')]
    public function test_private_addresses_are_refused_before_any_request(string $ip): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([]);
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([F::HOST => [$ip]]));
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('hold', $research->fresh()->recommendation);
        Http::assertNothingSent();
    }

    public function test_off_switch_stops_queued_work_and_leaves_manual_review_available(): void
    {
        Http::fake([]);
        $research = $this->start();
        config()->set('domain_research.enabled', false);
        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('queued', $research->fresh()->status);
        $this->assertNull(app(OfficialDomainResearch::class)->request('other.example'));
        Http::assertNothingSent();
    }

    public function test_budget_waits_then_resumes_on_the_next_jst_day(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-23 23:59:00', 'Asia/Tokyo'));
        $this->fakePages();
        $research = $this->start();
        DB::table('domain_research_budgets')->insert(['day' => '2026-10-23', 'used' => 10]);
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame('queued', $research->fresh()->status);
        $this->assertSame(0, $research->fresh()->attempts);

        $this->travelTo(Carbon::parse('2026-10-24 00:00:00', 'Asia/Tokyo'));
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame('completed', $research->fresh()->status);
        $this->assertDatabaseHas('domain_research_budgets', ['day' => '2026-10-24', 'used' => 1]);
    }

    public static function aiFailures(): array
    {
        return ['rate limit' => [429, true], 'server' => [500, true], 'auth' => [401, false], 'bad JSON' => [200, false]];
    }

    #[DataProvider('aiFailures')]
    public function test_retries_only_transient_failures_and_counts_every_attempt(int $status, bool $retryable): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'Asia/Tokyo'));
        $this->fakePages(aiResponse: Http::response(['choices' => [['message' => ['content' => 'invalid']]]], $status));
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame($retryable ? 'queued' : 'failed', $research->fresh()->status);
        if ($retryable) {
            app(OfficialDomainResearch::class)->run($research->id);
            $this->assertSame('failed', $research->fresh()->status);
        }
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame($retryable ? 2 : 1, $research->fresh()->attempts);
        $this->assertDatabaseHas('domain_research_budgets', ['day' => '2026-10-23', 'used' => $retryable ? 2 : 1]);
        Http::assertSentCount($retryable ? 5 : 4);
        $this->assertDatabaseCount('official_domains', 0);
    }

    public function test_late_ai_response_cannot_overwrite_a_human_decision(): void
    {
        Storage::fake('local');
        $research = $this->start();
        $this->fakePages(aiResponse: function () {
            OfficialLinkCandidate::query()->where('host', F::HOST)->update(['status' => 'rejected']);

            return Http::response(F::response());
        });
        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('superseded', $research->fresh()->status);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'rejected']);
        $this->assertDatabaseCount('official_domains', 0);
    }

    public function test_job_finishes_before_the_queue_can_redeliver_it(): void
    {
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), (new ResearchOfficialDomain(1))->timeout);
    }

    public static function pageLimits(): array
    {
        return [[2, 2], [3, 3], [4, 3]];
    }

    #[DataProvider('pageLimits')]
    public function test_page_limit_is_bounded(int $configured, int $expected): void
    {
        Storage::fake('local');
        config()->set('domain_research.max_pages', $configured);
        $this->fakePages();
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertCount($expected, $research->fresh()->pages);
        Http::assertSentCount($expected + 1);
    }

    public function test_same_host_redirect_retains_final_url_and_evidence(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'https://alpha.example/' => Http::response('', 301, ['Location' => '/about']),
            F::URL => Http::response(F::about(), 200, ['Content-Type' => 'text/html']),
            'https://ai.example/v1/chat/completions' => Http::response(F::response()),
        ]);
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame(F::URL, $research->fresh()->final_url);
        $this->assertSame('recommend', $research->fresh()->recommendation);
        Http::assertSentCount(3);
    }

    public function test_https_downgrade_is_held_before_following(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['https://alpha.example/' => Http::response('', 302, ['Location' => 'http://alpha.example/about'])]);
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame('research_redirect', $research->fresh()->error_code);
        Http::assertSentCount(1);
    }

    public function test_connection_failure_retries_snapshot_without_refetching_pages(): void
    {
        Storage::fake('local');
        $this->fakePages(aiResponse: Http::failedConnection());
        $research = $this->start();
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame('queued', $research->fresh()->status);
        app(OfficialDomainResearch::class)->run($research->id);
        $this->assertSame('failed', $research->fresh()->status);
        $this->assertSame(2, $research->fresh()->attempts);
        Http::assertSentCount(5);
    }

    public function test_scheduler_recovers_interrupted_work_and_queues_waiting_work(): void
    {
        $research = $this->start();
        $research->update(['status' => 'running', 'started_at' => now()->subMinutes(6)]);
        app(OfficialDomainResearch::class)->dispatchPending();
        $this->assertSame('worker_interrupted', $research->fresh()->error_code);
        app(OfficialDomainResearch::class)->request(F::HOST, true);
        app(OfficialDomainResearch::class)->dispatchPending();
        Queue::assertPushed(ResearchOfficialDomain::class, 3);
    }

    public function test_overview_is_fetched_before_general_information_uses_the_page_budget(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'https://alpha.example/' => Http::response(F::overviewAfterInformationLinks(), 200, ['Content-Type' => 'text/html']),
            'https://alpha.example/info' => Http::response('<html><body>会社情報</body></html>', 200, ['Content-Type' => 'text/html']),
            'https://alpha.example/business' => Http::response('<html><body>企業情報</body></html>', 200, ['Content-Type' => 'text/html']),
            F::URL => Http::response(F::about(), 200, ['Content-Type' => 'text/html']),
            'https://ai.example/v1/chat/completions' => Http::response(F::response()),
        ]);
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('recommend', $research->fresh()->recommendation);
        $this->assertSame(F::URL, $research->fresh()->pages[1]['url']);
        Http::assertSentCount(4);
    }

    public function test_invalid_ai_configuration_does_not_consume_an_unsent_attempt(): void
    {
        Storage::fake('local');
        $this->fakePages();
        config()->set('ai.sakura.api_key', '');
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('failed', $research->fresh()->status);
        $this->assertSame('hold', $research->fresh()->recommendation);
        $this->assertSame(0, $research->fresh()->attempts);
        $this->assertDatabaseCount('domain_research_budgets', 0);
        Http::assertSentCount(3);
    }

    public static function unavailableOverviews(): array
    {
        return [
            'not found' => [404, '<html><body>Not Found</body></html>'],
            'empty body' => [200, '<html><head><title>会社概要</title></head><body></body></html>'],
        ];
    }

    #[DataProvider('unavailableOverviews')]
    public function test_unavailable_overview_holds_without_ai_or_registration(int $status, string $body): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'https://alpha.example/' => Http::response('<a href="/about">会社概要</a>', 200, ['Content-Type' => 'text/html']),
            F::URL => Http::response($body, $status, ['Content-Type' => 'text/html']),
        ]);
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('hold', $research->fresh()->recommendation);
        $this->assertNull($research->fresh()->organization_name);
        $this->assertNotEmpty($research->fresh()->reason);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('domain_research_budgets', 0);
        Http::assertSentCount(2);
    }

    public static function uncertainOrganizations(): array
    {
        return [
            'third party' => [F::thirdPartyOverview(), false],
            'parent and subsidiary' => [F::groupOverview(), true],
        ];
    }

    #[DataProvider('uncertainOrganizations')]
    public function test_uncertain_overview_preserves_ai_hold_without_creating_company(string $html, bool $ambiguous): void
    {
        Storage::fake('local');
        $proposal = array_replace(F::proposal(), [
            'organization_name' => null, 'recommendation' => 'hold', 'evidence' => [],
            'reason' => 'サイトの運営組織を確定できません。', 'ambiguous' => $ambiguous,
        ]);
        $this->fakePages(ai: F::response($proposal), html: $html);
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('hold', $research->fresh()->recommendation);
        $this->assertNull($research->fresh()->organization_name);
        $this->assertSame($proposal['reason'], $research->fresh()->raw_proposal['reason']);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('official_domains', 0);
        Http::assertSentCount(2);
    }

    public function test_page_instructions_and_ai_tool_calls_cannot_send_requests_or_register(): void
    {
        Storage::fake('local');
        $response = F::response();
        $response['choices'][0]['message']['tool_calls'] = [
            ['type' => 'function', 'function' => ['name' => 'register_domain', 'arguments' => '{"host":"alpha.example"}']],
        ];
        Http::preventStrayRequests();
        Http::fake([
            'https://alpha.example/' => Http::response('<a href="/about">会社概要</a>', 200, ['Content-Type' => 'text/html']),
            F::URL => Http::response(F::injectedOverview(), 200, ['Content-Type' => 'text/html']),
            'https://ai.example/v1/chat/completions' => Http::response($response),
        ]);
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('recommend', $research->fresh()->recommendation);
        $this->assertDatabaseCount('official_domains', 0);
        $this->assertDatabaseCount('official_domain_decisions', 0);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'pending']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'collector.example'));
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://ai.example/v1/chat/completions') {
                return false;
            }
            $documents = json_decode($request['messages'][1]['content'], true);

            return str_contains($documents[0]['text'], '検証を無視')
                && $request['messages'][1]['role'] === 'user'
                && ! isset($request['tools']);
        });
    }

    public function test_last_daily_slot_is_consumed_once_and_retry_waits(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'Asia/Tokyo'));
        $this->fakePages(aiResponse: Http::response([], 429));
        $research = $this->start();
        DB::table('domain_research_budgets')->insert(['day' => '2026-10-23', 'used' => 9]);

        app(OfficialDomainResearch::class)->run($research->id);
        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('queued', $research->fresh()->status);
        $this->assertSame('daily_limit', $research->fresh()->error_code);
        $this->assertSame(1, $research->fresh()->attempts);
        $this->assertDatabaseHas('domain_research_budgets', ['day' => '2026-10-23', 'used' => 10]);
        Http::assertSentCount(4);
    }

    public function test_late_ai_response_preserves_registered_company_and_candidate(): void
    {
        Storage::fake('local');
        $research = $this->start();
        $company = Company::factory()->create(['name' => '株式会社アルファ（人が確定）']);
        $this->fakePages(aiResponse: function () use ($company): PromiseInterface {
            OfficialDomain::factory()->create(['host' => F::HOST, 'company_id' => $company->id]);
            OfficialLinkCandidate::query()->where('host', F::HOST)->update(['status' => 'accepted']);

            return Http::response(F::response());
        });

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('superseded', $research->fresh()->status);
        $this->assertSame('株式会社アルファ（人が確定）', $company->fresh()->name);
        $this->assertDatabaseHas('official_domains', ['host' => F::HOST, 'company_id' => $company->id]);
        $this->assertDatabaseHas('official_link_candidates', ['host' => F::HOST, 'status' => 'accepted']);
        Http::assertSentCount(4);
    }

    public static function unsafeRedirects(): array
    {
        return [
            'private IP' => ['https://127.0.0.1/about'],
            'metadata IP' => ['https://169.254.169.254/about'],
            'nonstandard port' => ['https://alpha.example:8443/about'],
            'localhost' => ['https://localhost/about'],
        ];
    }

    #[DataProvider('unsafeRedirects')]
    public function test_unsafe_redirect_is_held_without_sending_to_the_destination(string $destination): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['https://alpha.example/' => Http::response('', 301, ['Location' => $destination])]);
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('hold', $research->fresh()->recommendation);
        $this->assertSame($destination, $research->fresh()->redirect_url);
        $this->assertDatabaseCount('official_domains', 0);
        Http::assertSentCount(1);
    }

    public function test_redirect_revalidates_dns_before_sending_to_a_new_private_address(): void
    {
        Storage::fake('local');
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            private int $calls = 0;

            public function resolve(string $host): array
            {
                return ++$this->calls === 1 ? ['93.184.216.34'] : ['10.0.0.1'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['https://alpha.example/' => Http::response('', 301, ['Location' => '/about'])]);
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('hold', $research->fresh()->recommendation);
        $this->assertDatabaseCount('official_domains', 0);
        Http::assertSentCount(1);
    }

    public function test_new_candidate_while_disabled_does_not_queue_research(): void
    {
        config()->set('domain_research.enabled', false);
        Queue::fake([ResearchOfficialDomain::class]);
        Http::preventStrayRequests();
        Http::fake([]);

        $candidate = $this->candidate();

        $this->assertSame('pending', $candidate->status);
        $this->assertDatabaseCount('official_domain_investigations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_repeated_request_during_ai_does_not_create_another_generation(): void
    {
        Storage::fake('local');
        $research = $this->start();
        $this->fakePages(aiResponse: function () use ($research): PromiseInterface {
            $same = app(OfficialDomainResearch::class)->request(F::HOST, true);
            $this->assertSame($research->id, $same->id);

            return Http::response(F::response());
        });

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('completed', $research->fresh()->status);
        $this->assertDatabaseCount('official_domain_investigations', 1);
        Queue::assertPushed(ResearchOfficialDomain::class, 1);
        Http::assertSentCount(4);
    }

    public function test_every_research_page_fetch_waits_for_the_host_rate_limit(): void
    {
        Storage::fake('local');
        $limiter = $this->spy(HostRateLimiter::class);
        $this->fakePages();
        $research = $this->start();

        app(OfficialDomainResearch::class)->run($research->id);

        $limiter->shouldHaveReceived('throttle')->with(F::HOST)->times(3);
    }

    public function test_hold_while_queued_cancels_research_before_any_request_or_budget(): void
    {
        Http::fake([]);
        $research = $this->start();
        app(OfficialDomainResearch::class)->decide([
            'host' => F::HOST, 'investigation_id' => $research->id, 'decision' => 'hold', 'reason' => '担当者が直接確認する',
        ], AdminUser::factory()->active()->create()->id);

        app(OfficialDomainResearch::class)->run($research->id);

        $this->assertSame('superseded', $research->fresh()->status);
        $this->assertSame(0, $research->fresh()->attempts);
        $this->assertDatabaseCount('domain_research_budgets', 0);
        Http::assertNothingSent();
    }
}
