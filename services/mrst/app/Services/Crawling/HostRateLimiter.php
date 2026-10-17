<?php

namespace App\Services\Crawling;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class HostRateLimiter
{
    public function __construct(private readonly CacheRepository $cache)
    {
    }

    public function throttle(string $host): void
    {
        $minIntervalMs = (int) config('crawler.host_rate.min_interval_ms', 2000);
        if ($minIntervalMs <= 0) {
            return;
        }

        $key = 'msnc:host-rate:'.strtolower($host);
        $last = $this->cache->get($key);
        $now = microtime(true);
        if ($last !== null) {
            $elapsedMs = ($now - (float) $last) * 1000;
            if ($elapsedMs < $minIntervalMs) {
                $sleepMs = (int) ceil($minIntervalMs - $elapsedMs);
                usleep($sleepMs * 1000);
            }
        }
        $this->cache->put($key, microtime(true), 60);
    }
}
