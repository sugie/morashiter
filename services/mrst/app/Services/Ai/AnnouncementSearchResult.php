<?php

namespace App\Services\Ai;

/**
 * Validates the matcher's answers against what we gave it: numbers must point into the list
 * and quotes must appear in the fetched page. Anything else is dropped, never trusted.
 */
class AnnouncementSearchResult
{
    /**
     * @param  array<string, mixed>  $raw
     * @return list<int> zero-based positions, best first, unique, at most $max
     */
    public static function selected(array $raw, int $count, int $max = 3): array
    {
        $selected = $raw['selected'] ?? null;
        if (! is_array($selected) || ! array_is_list($selected)) {
            return [];
        }
        $positions = [];
        foreach ($selected as $number) {
            if (is_int($number) && $number >= 1 && $number <= $count && ! in_array($number - 1, $positions, true)) {
                $positions[] = $number - 1;
            }
            if (count($positions) >= $max) {
                break;
            }
        }

        return $positions;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{verdict: string, quotes: list<string>}
     */
    public static function verdict(array $raw, string $pageText): array
    {
        $verdict = in_array($raw['verdict'] ?? null, ['same', 'different', 'unclear'], true) ? $raw['verdict'] : 'unclear';
        $quotes = [];
        $normalizedPage = OfficialDomainResearchResult::normalize($pageText);
        foreach (is_array($raw['quotes'] ?? null) ? $raw['quotes'] : [] as $quote) {
            if (! is_string($quote) || trim($quote) === '' || mb_strlen($quote) > 300) {
                continue;
            }
            if (str_contains($normalizedPage, OfficialDomainResearchResult::normalize($quote)) && count($quotes) < 2) {
                $quotes[] = trim($quote);
            }
        }
        // "Same" must be backed by at least one quote that really is on the page.
        if ($verdict === 'same' && $quotes === []) {
            $verdict = 'unclear';
        }

        return ['verdict' => $verdict, 'quotes' => $quotes];
    }
}
