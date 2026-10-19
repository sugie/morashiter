<?php

namespace App\Providers;

use App\Exceptions\AiAnalysisFailure;
use App\Services\Ai\IncidentAnalyzer;
use App\Services\Ai\MockIncidentAnalyzer;
use App\Services\Ai\SakuraIncidentAnalyzer;
use App\Services\Crawling\HostResolver;
use App\Services\Crawling\SystemDnsHostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HostResolver::class, SystemDnsHostResolver::class);

        $this->app->bind(IncidentAnalyzer::class, function (): IncidentAnalyzer {
            $config = (array) config('ai', []);

            return match ($config['provider'] ?? 'mock') {
                'mock' => new MockIncidentAnalyzer,
                'sakura' => SakuraIncidentAnalyzer::fromConfig($config),
                default => throw new AiAnalysisFailure('AI_PROVIDER must be "mock" or "sakura".'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('admin-login', function (Request $request): Limit {
            $email = strtolower(trim(substr((string) $request->input('email', ''), 0, 254)));

            return Limit::perMinute(5)->by(hash('sha256', $email.'|'.$request->ip()));
        });

        TrustProxies::at(config('infrastructure.trusted_proxies', []));
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );
    }
}
