<?php

return [
    'fetch_enabled' => (bool) env('MSNC_FETCH_ENABLED', false),

    'allowed_schemes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MSNC_ALLOWED_SCHEMES', 'https')),
    ))),

    'allowed_ports' => [80, 443],

    'trusted_private_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MSNC_TRUSTED_PRIVATE_HOSTS', '')),
    ))),

    'fetch' => [
        'connect_timeout_seconds' => (int) env('MSNC_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => (int) env('MSNC_REQUEST_TIMEOUT', 30),
        'max_body_bytes' => (int) env('MSNC_MAX_BODY_BYTES', 2 * 1024 * 1024),
        'max_redirects' => (int) env('MSNC_MAX_REDIRECTS', 3),
        'user_agent' => (string) env('MSNC_USER_AGENT', 'MorashiterCrawler/0.1 (+contact: ops@example.invalid)'),
    ],

    'host_rate' => [
        'min_interval_ms' => (int) env('MSNC_HOST_MIN_INTERVAL_MS', 2000),
    ],

    'max_items_per_run' => (int) env('MSNC_MAX_ITEMS_PER_RUN', 100),
];
