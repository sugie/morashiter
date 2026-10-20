<?php

namespace App\Services\Publishing;

use App\Exceptions\PublishFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Clears the Sakura Web Accelerator cache for the public site. Success needs both a 2xx
 * response and no explicit failure flag in the body. Credentials never appear in messages.
 */
class CdnPurger
{
    public function purge(): void
    {
        $cdn = config('msgn.cdn');
        if (empty($cdn['token']) || empty($cdn['secret'])) {
            throw new PublishFailure('CDN purge failed: MSGN_CDN_API_TOKEN / MSGN_CDN_API_SECRET are not set');
        }

        try {
            $response = Http::withBasicAuth((string) $cdn['token'], (string) $cdn['secret'])
                ->acceptJson()
                ->timeout(30)
                ->post((string) $cdn['api_url'], ['Site' => ['Domain' => (string) $cdn['domain']]]);
        } catch (ConnectionException) {
            throw new PublishFailure('CDN purge failed: connection error');
        }

        $flag = $response->json('is_ok') ?? $response->json('Success');
        if (! $response->successful() || $flag === false) {
            throw new PublishFailure('CDN purge failed: HTTP '.$response->status());
        }
    }
}
