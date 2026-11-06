<?php

return [
    // Whole feature; false hides the buttons and stops the scheduler.
    'enabled' => (bool) env('ANNOUNCEMENT_SEARCH_ENABLED', false),
    // false keeps the rule-based candidates only (no AI calls).
    'ai' => (bool) env('ANNOUNCEMENT_SEARCH_AI', true),
    // Queue a search for every candidate whose top page had no incident link.
    'auto' => (bool) env('ANNOUNCEMENT_SEARCH_AUTO', true),
    // AI searches per Japanese calendar day, separate from AI_MAX_ANALYSES_PER_DAY.
    'daily_limit' => (int) env('ANNOUNCEMENT_SEARCH_DAILY_LIMIT', 20),
    // Pages fetched per search while collecting candidates.
    'max_pages' => (int) env('ANNOUNCEMENT_SEARCH_MAX_PAGES', 10),
    'max_candidates' => 15,
    'max_verified' => 3,
    'budget_timezone' => 'Asia/Tokyo',
];
