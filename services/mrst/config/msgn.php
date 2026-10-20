<?php

return [
    // Master switch for publishing to the public site. Off until the S3 and CDN keys are in place.
    'enabled' => (bool) env('MSGN_ENABLED', false),

    // false keeps the site noindex (preview); true for the general public release.
    'indexable' => (bool) env('MSGN_INDEXABLE', false),

    'public_url' => rtrim((string) env('MSGN_PUBLIC_URL', 'https://www.morashiter.com'), '/'),

    // Where the static site builder (prototype) lives inside the worker image.
    'site_root' => (string) env('MSGN_SITE_ROOT', '/opt/site'),

    // After uploading, release.json is read back through the CDN this many times before giving up.
    'confirm_attempts' => (int) env('MSGN_CONFIRM_ATTEMPTS', 10),
    'confirm_delay_seconds' => (int) env('MSGN_CONFIRM_DELAY_SECONDS', 30),

    // Sakura Cloud Web Accelerator cache purge (cloud API token restricted to Web Accelerator).
    'cdn' => [
        'api_url' => (string) env('MSGN_CDN_API_URL', 'https://secure.sakura.ad.jp/cloud/zone/is1a/api/webaccel/1.0/deleteallcache'),
        'domain' => (string) env('MSGN_CDN_DOMAIN', 'www.morashiter.com'),
        'token' => env('MSGN_CDN_API_TOKEN'),
        'secret' => env('MSGN_CDN_API_SECRET'),
    ],
];
