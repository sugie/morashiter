<?php

namespace Tests\Feature\Crawling;

use App\Services\Crawling\HostRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HostRateLimiterTest extends TestCase
{
    private function limiter(): HostRateLimiter
    {
        return new HostRateLimiter($this->app->make(CacheRepository::class));
    }

    public function test_zero_interval_short_circuits_without_sleep(): void
    {
        Config::set('crawler.host_rate.min_interval_ms', 0);
        $limiter = $this->limiter();

        $start = microtime(true);
        $limiter->throttle('fixture-news');
        $limiter->throttle('fixture-news');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(50, $elapsedMs);
    }

    public function test_second_call_waits_for_minimum_interval(): void
    {
        Config::set('crawler.host_rate.min_interval_ms', 150);
        $limiter = $this->limiter();

        $limiter->throttle('fixture-news');
        $start = microtime(true);
        $limiter->throttle('fixture-news');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(120, $elapsedMs);
    }

    public function test_different_hosts_do_not_block_each_other(): void
    {
        Config::set('crawler.host_rate.min_interval_ms', 500);
        $limiter = $this->limiter();

        $limiter->throttle('alpha.example.test');
        $start = microtime(true);
        $limiter->throttle('beta.example.test');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(100, $elapsedMs);
    }
}
