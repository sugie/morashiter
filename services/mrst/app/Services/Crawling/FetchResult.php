<?php

namespace App\Services\Crawling;

class FetchResult
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $finalUrl,
        public readonly int $elapsedMs,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
