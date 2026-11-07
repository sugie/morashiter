<?php

namespace App\Services\Crawling;

use App\Models\OfficialLinkCandidate;

/**
 * Browser search links an admin can open to find an announcement by hand. Nothing is sent from
 * the server: the link only opens in the admin's own browser.
 */
class AnnouncementSearchLinks
{
    private const SEARCH = 'https://www.google.com/search?q=';

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function for(OfficialLinkCandidate $candidate): array
    {
        $site = RegistrableDomain::of($candidate->host) ?? $candidate->host;
        $terms = self::terms($candidate->sourceDocument?->title ?? $candidate->anchor_text ?? '');
        $links = [['label' => '検索で探す', 'url' => self::SEARCH.rawurlencode(trim('site:'.$site.' '.$terms))]];
        if (preg_match('/\.(lg|go)\.jp$/', $candidate->host)) {
            $links[] = ['label' => '報道発表で探す', 'url' => self::SEARCH.rawurlencode(trim('site:'.$site.' 報道発表 '.$terms))];
        }

        return $links;
    }

    /** News headlines end with " - organisation"; the organisation is already in site:. */
    private static function terms(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return trim((string) preg_replace('/\s+[-－–—]\s+[^-－–—]+$/u', '', $title));
    }
}
