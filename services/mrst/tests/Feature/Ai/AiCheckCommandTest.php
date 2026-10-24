<?php

namespace Tests\Feature\Ai;

use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Services\Ai\AiUsageBudget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiCheckCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const API_KEY = 'uuid-0000:secret-must-never-leak';

    private const ENDPOINT = 'https://ai.example.invalid/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('ai.provider', 'mock');
        Config::set('ai.max_analyses_per_day', 20);
        Config::set('ai.sakura', ['base_url' => 'https://ai.example.invalid/v1/', 'model' => 'llm-jp-3.1-8x13b-instruct4', 'api_key' => null]);
        Config::set('domain_research.enabled', false);
        Config::set('domain_research.daily_limit', 10);
    }

    private function useSakura(): void
    {
        Config::set('ai.provider', 'sakura');
        Config::set('ai.sakura.api_key', self::API_KEY);
    }

    public function test_mock_reports_settings_usage_and_backlog_without_calling_out(): void
    {
        OfficialDocument::factory()->create();
        IncidentReview::query()->create([
            'official_document_id' => OfficialDocument::factory()->create()->id,
            'status' => 'analysis_failed', 'analyzer' => 'mock', 'error_code' => 'pdf_text_unsupported', 'attempts' => 0,
        ]);

        $this->artisan('ai:check')
            ->expectsOutputToContain('provider=mock')
            ->expectsOutputToContain('api_key=missing')
            ->expectsOutputToContain('analyses today (sakura): 0 of 20')
            ->expectsOutputToContain('domain research: disabled today 0 of 10')
            ->expectsOutputToContain('documents without a review: 1')
            ->expectsOutputToContain('reviews analysis_failed/mock/pdf_text_unsupported: 1')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_sakura_settings_are_shown_without_the_key(): void
    {
        $this->useSakura();
        app(AiUsageBudget::class)->reserve('sakura');

        $this->artisan('ai:check')
            ->expectsOutputToContain('provider=sakura model=llm-jp-3.1-8x13b-instruct4')
            ->expectsOutputToContain('api_key=set')
            ->expectsOutputToContain('analyses today (sakura): 1 of 20')
            ->doesntExpectOutputToContain('secret-must-never-leak')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_sakura_without_a_key_or_https_url_fails(): void
    {
        Config::set('ai.provider', 'sakura');
        Config::set('ai.sakura.base_url', 'http://ai.example.invalid/v1');

        $this->artisan('ai:check', ['--ping' => true])
            ->expectsOutputToContain('SAKURA_AI_BASE_URL must be an https:// URL.')
            ->expectsOutputToContain('SAKURA_AI_API_KEY is required when AI_PROVIDER=sakura.')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, app(AiUsageBudget::class)->usedToday('sakura'));
    }

    public function test_unknown_provider_fails(): void
    {
        Config::set('ai.provider', 'openai');

        $this->artisan('ai:check')->expectsOutputToContain('AI_PROVIDER must be "mock" or "sakura".')->assertExitCode(1);
    }

    public function test_domain_research_enabled_without_sakura_warns(): void
    {
        Config::set('domain_research.enabled', true);

        $this->artisan('ai:check')
            ->expectsOutputToContain('DOMAIN_RESEARCH_ENABLED=true but AI_PROVIDER is not sakura')
            ->assertExitCode(0);
    }

    public function test_ping_sends_one_small_request_and_counts_it(): void
    {
        $this->useSakura();
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->artisan('ai:check', ['--ping' => true])
            ->expectsOutputToContain('ping: HTTP 200')
            ->doesntExpectOutputToContain('secret-must-never-leak')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
            && $request['model'] === 'llm-jp-3.1-8x13b-instruct4'
            && $request['max_tokens'] === 8);
        $this->assertSame(1, app(AiUsageBudget::class)->usedToday('sakura'));
    }

    public function test_ping_reports_an_auth_failure_without_retrying(): void
    {
        $this->useSakura();
        Http::fake([self::ENDPOINT => Http::response(['error' => 'unauthorized'], 401)]);

        $this->artisan('ai:check', ['--ping' => true])
            ->expectsOutputToContain('ping: HTTP 401')
            ->expectsOutputToContain('Check SAKURA_AI_API_KEY')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_ping_respects_the_daily_cap(): void
    {
        $this->useSakura();
        Config::set('ai.max_analyses_per_day', 1);
        app(AiUsageBudget::class)->reserve('sakura');

        $this->artisan('ai:check', ['--ping' => true])
            ->expectsOutputToContain('Daily cap reached')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_ping_needs_sakura(): void
    {
        $this->artisan('ai:check', ['--ping' => true])
            ->expectsOutputToContain('--ping needs AI_PROVIDER=sakura.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
