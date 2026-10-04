<?php

declare(strict_types=1);

return [
    'crawl' => [
        'connect_timeout' => (int) env('CRAWL_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout' => (int) env('CRAWL_TIMEOUT_SECONDS', 30),
        'max_bytes' => (int) env('CRAWL_MAX_BYTES', 2 * 1024 * 1024),
        'allowed_ports' => array_values(array_filter(array_map(
            static fn (string $port): int => (int) trim($port),
            explode(',', (string) env('CRAWL_ALLOWED_PORTS', '80,443')),
        ))),
    ],

    'ai' => [
        // mock | sakura. The cost-free provider is the safe default.
        'provider' => strtolower((string) env('AI_PROVIDER', 'mock')),
        'base_url' => env('SAKURA_AI_BASE_URL'),
        'api_key' => env('SAKURA_AI_API_KEY'),
        'model' => env('SAKURA_AI_MODEL'),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 30),
        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),
    ],
];
