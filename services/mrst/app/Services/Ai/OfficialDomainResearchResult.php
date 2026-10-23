<?php

namespace App\Services\Ai;

class OfficialDomainResearchResult
{
    /** Validate against the exact pages supplied to the model, never a model-selected URL. */
    public static function validate(array $raw, array $pages): array
    {
        $name = is_string($raw['organization_name'] ?? null) ? trim($raw['organization_name']) : null;
        $name = $name === '' ? null : $name;
        $reason = is_string($raw['reason'] ?? null) ? trim($raw['reason']) : '';
        $evidence = $raw['evidence'] ?? [];
        $valid = in_array($raw['recommendation'] ?? null, ['recommend', 'hold'], true)
            && is_bool($raw['ambiguous'] ?? null)
            && $reason !== '' && mb_strlen($reason) <= 2000
            && ($name === null || mb_strlen($name) <= 255)
            && is_array($evidence) && array_is_list($evidence) && count($evidence) <= 5;
        $verified = [];
        if ($valid) {
            foreach ($evidence as $item) {
                if (! is_array($item) || ! is_string($item['url'] ?? null) || ! is_string($item['quote'] ?? null)
                    || trim($item['quote']) === '' || mb_strlen($item['quote']) > 2000) {
                    $valid = false;
                    break;
                }
                $matches = array_filter($pages, fn (array $page): bool => $page['url'] === $item['url']
                    && $page['is_overview'] && $name !== null
                    && str_contains(self::normalize($page['text']), self::normalize($name))
                    && str_contains(self::normalize($page['text']), self::normalize($item['quote'])));
                if ($matches === []) {
                    $valid = false;
                    break;
                }
                $verified[] = ['url' => $item['url'], 'quote' => trim($item['quote'])];
            }
        }
        $recommend = $valid && $name !== null && $verified !== []
            && $raw['recommendation'] === 'recommend' && $raw['ambiguous'] === false;

        return [
            'organization_name' => $valid ? $name : null,
            'recommendation' => $recommend ? 'recommend' : 'hold',
            'evidence' => $verified,
            'reason' => mb_substr($reason, 0, 2000),
            'validation_reason' => $recommend ? null : '概要の根拠・組織の一意性を確認できません。人による確認が必要です。',
        ];
    }

    public static function normalize(string $text): string
    {
        return (string) preg_replace('/[\s\x{3000}]+/u', '', $text);
    }
}
