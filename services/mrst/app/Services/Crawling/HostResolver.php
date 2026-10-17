<?php

namespace App\Services\Crawling;

interface HostResolver
{
    /**
     * Resolve a hostname to the list of IPv4 and IPv6 addresses it currently points to.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
