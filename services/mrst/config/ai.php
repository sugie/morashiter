<?php

return [
    // mock | sakura. mock never sends anything outside and is the safe default;
    // switching back to mock is the emergency stop (docs/runbooks/sakura-ai-engine.md).
    'provider' => strtolower(trim((string) env('AI_PROVIDER', 'mock'))),

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 30),

    // Total attempts = max_retries + 1. Only connection failures, 429 and 5xx are retried.
    'max_retries' => (int) env('AI_MAX_RETRIES', 1),

    // Official announcements are short; the cap bounds per-request cost.
    'max_input_characters' => (int) env('AI_MAX_INPUT_CHARACTERS', 20000),

    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),

    // Cost guard: real (non-mock) analyses per day; the rest wait for msic:analyze-pending.
    'max_analyses_per_day' => (int) env('AI_MAX_ANALYSES_PER_DAY', 20),

    'sakura' => [
        'base_url' => env('SAKURA_AI_BASE_URL'),
        'model' => env('SAKURA_AI_MODEL'),
        'api_key' => env('SAKURA_AI_API_KEY'),
    ],
];
