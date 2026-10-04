<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IncidentAnalyzer;
use App\Infrastructure\Ai\MockIncidentAnalyzer;
use App\Infrastructure\Ai\SakuraIncidentAnalyzer;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            IncidentAnalyzer::class,
            config('incident_collection.ai.provider') === 'sakura'
                ? SakuraIncidentAnalyzer::class
                : MockIncidentAnalyzer::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
