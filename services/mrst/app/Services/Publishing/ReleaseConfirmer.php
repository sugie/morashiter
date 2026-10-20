<?php

namespace App\Services\Publishing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads release.json back through the CDN until it shows the new release id.
 * An upload is not a publish until the public URL serves it.
 */
class ReleaseConfirmer
{
    public function confirm(string $releaseId): bool
    {
        $attempts = max(1, (int) config('msgn.confirm_attempts', 10));
        $delay = max(0, (int) config('msgn.confirm_delay_seconds', 30));
        $url = config('msgn.public_url').'/release.json';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(15)->withHeaders(['Cache-Control' => 'no-cache'])->get($url, ['confirm' => Str::random(12)]);
                if ($response->successful() && $response->json('releaseId') === $releaseId) {
                    return true;
                }
            } catch (ConnectionException) {
                // Try again after the delay.
            }
            if ($attempt < $attempts && $delay > 0) {
                sleep($delay);
            }
        }

        return false;
    }
}
