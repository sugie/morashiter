<?php

namespace Tests\Feature\Ai;

use App\Exceptions\AiAnalysisFailure;
use App\Services\Ai\IncidentAnalyzer;
use App\Services\Ai\MockIncidentAnalyzer;
use App\Services\Ai\SakuraIncidentAnalyzer;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncidentAnalyzerBindingTest extends TestCase
{
    private function configureSakura(array $overrides = []): void
    {
        Config::set('ai.provider', 'sakura');
        Config::set('ai.sakura', array_merge([
            'base_url' => 'https://ai.example.invalid/v1',
            'model' => 'test-model',
            'api_key' => 'test-key-not-real',
        ], $overrides));
    }

    public function test_mock_is_bound_by_default(): void
    {
        $this->assertSame('mock', config('ai.provider'));
        $this->assertInstanceOf(MockIncidentAnalyzer::class, $this->app->make(IncidentAnalyzer::class));
    }

    public function test_default_limits_suit_official_announcements(): void
    {
        $this->assertSame(20000, config('ai.max_input_characters'));
        $this->assertSame(1, config('ai.max_retries'));
    }

    public function test_sakura_is_bound_when_fully_configured(): void
    {
        $this->configureSakura();

        $this->assertInstanceOf(SakuraIncidentAnalyzer::class, $this->app->make(IncidentAnalyzer::class));
    }

    public static function missingSakuraSettings(): array
    {
        return [
            'base url' => ['base_url', 'SAKURA_AI_BASE_URL'],
            'model' => ['model', 'SAKURA_AI_MODEL'],
            'api key' => ['api_key', 'SAKURA_AI_API_KEY'],
        ];
    }

    #[DataProvider('missingSakuraSettings')]
    public function test_sakura_binding_fails_clearly_when_a_setting_is_missing(string $key, string $envName): void
    {
        $this->configureSakura([$key => '  ']);

        try {
            $this->app->make(IncidentAnalyzer::class);
            $this->fail('Resolving the sakura analyzer without '.$envName.' should fail.');
        } catch (AiAnalysisFailure $e) {
            $this->assertStringContainsString($envName, $e->getMessage());
            $this->assertStringContainsString('AI_PROVIDER=sakura', $e->getMessage());
            $this->assertStringNotContainsString('test-key-not-real', $e->getMessage());
        }
    }

    public function test_unknown_provider_is_rejected_instead_of_silently_falling_back(): void
    {
        Config::set('ai.provider', 'openai');

        $this->expectException(AiAnalysisFailure::class);
        $this->expectExceptionMessage('AI_PROVIDER');

        $this->app->make(IncidentAnalyzer::class);
    }
}
