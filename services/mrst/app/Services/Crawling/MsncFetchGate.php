<?php

namespace App\Services\Crawling;

/**
 * Single switch every msnc entry point checks before any outbound request.
 */
class MsncFetchGate
{
    /**
     * @return 'fetch_disabled'|'user_agent_placeholder'|null null when fetching may proceed
     */
    public function closedReason(): ?string
    {
        if (! config('crawler.fetch_enabled', false)) {
            return 'fetch_disabled';
        }

        if (app()->environment('production') && str_contains((string) config('crawler.fetch.user_agent'), '.invalid')) {
            return 'user_agent_placeholder';
        }

        return null;
    }
}
