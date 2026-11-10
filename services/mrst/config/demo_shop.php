<?php

return [
    'enabled' => (bool) env('DEMO_SHOP_ENABLED', false),
    'host' => 'aozora-zakka.localhost',
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_SANDBOX_CLIENT_ID'),
        'secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
        'merchant_id' => env('PAYPAL_SANDBOX_MERCHANT_ID'),
    ],
    'ai' => [
        'enabled' => (bool) env('DEMO_AI_ENABLED', false),
        'base_url' => env('DEMO_AI_BASE_URL'),
        'key' => env('DEMO_AI_API_KEY'),
        'model' => env('DEMO_AI_MODEL'),
        'daily_limit' => (int) env('DEMO_AI_DAILY_LIMIT', 50),
    ],
];
