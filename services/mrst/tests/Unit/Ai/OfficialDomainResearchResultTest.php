<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\OfficialDomainResearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfficialDomainResearchFixtures as F;

class OfficialDomainResearchResultTest extends TestCase
{
    public function test_recommends_only_a_supported_organization_and_preserves_system_urls(): void
    {
        $raw = F::proposal() + ['candidate_host' => 'evil.example', 'final_url' => 'https://evil.example'];
        $result = OfficialDomainResearchResult::validate($raw, F::pages());

        $this->assertSame('recommend', $result['recommendation']);
        $this->assertSame('株式会社アルファ', $result['organization_name']);
        $this->assertArrayNotHasKey('candidate_host', $result);
        $this->assertArrayNotHasKey('final_url', $result);
    }

    public static function unsupported(): array
    {
        return [
            'missing name' => [['organization_name' => null]],
            'invalid recommendation' => [['recommendation' => 'approved']],
            'missing evidence' => [['evidence' => []]],
            'invented url' => [['evidence' => [['url' => 'https://evil.example', 'quote' => F::TEXT]]]],
            'invented quote' => [['evidence' => [['url' => F::URL, 'quote' => '架空の引用']]]],
            'ambiguous group' => [['ambiguous' => true]],
            'unknown ambiguity' => [['ambiguous' => null]],
            'blank reason' => [['reason' => '']],
            'different organization' => [['organization_name' => '株式会社ベータ']],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_holds_unsupported_proposals(array $changes): void
    {
        $result = OfficialDomainResearchResult::validate(array_replace(F::proposal(), $changes), F::pages());

        $this->assertSame('hold', $result['recommendation']);
        $this->assertNotEmpty($result['validation_reason']);
    }

    public function test_logo_only_page_is_not_evidence_and_whitespace_is_normalized(): void
    {
        $pages = F::pages();
        $pages[0]['is_overview'] = false;
        $this->assertSame('hold', OfficialDomainResearchResult::validate(F::proposal(), $pages)['recommendation']);
        $pages[0]['is_overview'] = true;
        $pages[0]['text'] = str_replace('株式会社アルファ', "株式会社アルファ\n", F::TEXT);
        $this->assertSame('recommend', OfficialDomainResearchResult::validate(F::proposal(), $pages)['recommendation']);
    }

    public function test_quote_from_another_fetched_page_is_not_evidence_for_this_url(): void
    {
        $pages = F::pages();
        $pages[0]['text'] = '会社概要 商号：株式会社アルファ';
        $pages[] = ['url' => 'https://alpha.example/profile', 'text' => F::TEXT, 'is_overview' => true];

        $result = OfficialDomainResearchResult::validate(F::proposal(), $pages);

        $this->assertSame('hold', $result['recommendation']);
        $this->assertSame([], $result['evidence']);
    }

    public static function missingFields(): array
    {
        return [
            'name' => ['organization_name'], 'recommendation' => ['recommendation'],
            'evidence' => ['evidence'], 'reason' => ['reason'], 'ambiguity' => ['ambiguous'],
        ];
    }

    #[DataProvider('missingFields')]
    public function test_missing_required_field_never_recommends(string $field): void
    {
        $proposal = F::proposal();
        unset($proposal[$field]);

        $result = OfficialDomainResearchResult::validate($proposal, F::pages());

        $this->assertSame('hold', $result['recommendation']);
        $this->assertNotEmpty($result['validation_reason']);
    }
}
