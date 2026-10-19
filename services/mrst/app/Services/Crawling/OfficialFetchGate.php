<?php

namespace App\Services\Crawling;

/**
 * Switch checked before msic sends any request to a company site.
 */
class OfficialFetchGate
{
    /**
     * @return 'fetch_disabled'|'user_agent_placeholder'|null null when fetching may proceed
     */
    public function closedReason(): ?string
    {
        if (! config('crawler.official_fetch_enabled', false)) {
            return 'fetch_disabled';
        }

        if (app()->environment('production') && str_contains((string) config('crawler.fetch.user_agent'), '.invalid')) {
            return 'user_agent_placeholder';
        }

        return null;
    }
}
