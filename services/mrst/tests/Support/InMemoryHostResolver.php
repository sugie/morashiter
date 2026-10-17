<?php

namespace Tests\Support;

use App\Services\Crawling\HostResolver;

class InMemoryHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hostToIps
     */
    public function __construct(private readonly array $hostToIps)
    {
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->hostToIps[strtolower($host)] ?? [];
    }
}
