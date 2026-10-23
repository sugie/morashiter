<?php

return [
    'enabled' => (bool) env('DOMAIN_RESEARCH_ENABLED', false),
    'max_pages' => (int) env('DOMAIN_RESEARCH_MAX_PAGES', 3),
    'daily_limit' => (int) env('DOMAIN_RESEARCH_DAILY_LIMIT', 10),
    'max_attempts' => (int) env('DOMAIN_RESEARCH_MAX_ATTEMPTS', 2),
    'budget_timezone' => 'Asia/Tokyo',
    'rule_version' => 'overview-human-review-v1',
    'prompt_version' => 'organization-evidence-v1',
];
