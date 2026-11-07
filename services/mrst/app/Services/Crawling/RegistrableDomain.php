<?php

namespace App\Services\Crawling;

/**
 * The part of a host one organisation registers (e.g. goodsmile.com, alpha-corp.co.jp,
 * pref.fukuoka.lg.jp), using the bundled suffix list. Used only to offer sibling hosts for a
 * human to confirm; it never makes a host official by itself.
 */
class RegistrableDomain
{
    /** @var array{exact: array<string, true>, wildcard: array<string, true>}|null */
    private static ?array $rules = null;

    public static function of(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            return null;
        }
        $labels = explode('.', $host);
        $suffixLength = self::suffixLength($labels);
        if (count($labels) <= $suffixLength) {
            return null;
        }

        return implode('.', array_slice($labels, -($suffixLength + 1)));
    }

    public static function same(string $left, string $right): bool
    {
        $domain = self::of($left);

        return $domain !== null && $domain === self::of($right);
    }

    /**
     * @param  list<string>  $labels
     */
    private static function suffixLength(array $labels): int
    {
        $rules = self::rules();
        $longest = 1;
        $count = count($labels);
        for ($length = 1; $length <= $count; $length++) {
            $candidate = implode('.', array_slice($labels, -$length));
            if (isset($rules['exact'][$candidate])) {
                $longest = $length;
            }
            // "*.lg.jp": any single label in front of lg.jp is itself a suffix.
            if ($length > 1 && isset($rules['wildcard'][implode('.', array_slice($labels, -($length - 1)))])) {
                $longest = $length;
            }
        }

        return $longest;
    }

    /**
     * @return array{exact: array<string, true>, wildcard: array<string, true>}
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $rules = ['exact' => [], 'wildcard' => []];
        foreach (file(resource_path('data/public_suffixes.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, '*.')) {
                $rules['wildcard'][substr($line, 2)] = true;
            } else {
                $rules['exact'][$line] = true;
            }
        }

        return self::$rules = $rules;
    }
}
