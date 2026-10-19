<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\IncidentAnalysis;
use App\Services\Ai\MockIncidentAnalyzer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IncidentAnalysisTest extends TestCase
{
    public function test_from_array_normalises_values_and_always_requires_human_review(): void
    {
        $analysis = IncidentAnalysis::fromArray([
            'organization' => ' 株式会社サンプル ',
            'incident_title' => '',
            'affected_count_text' => '約1,600件',
            'data_types' => ['氏名', 42, 'メールアドレス'],
            'disclosed_at' => '2026-10-01',
            'response_status' => null,
            'summary' => ' 不正アクセスの可能性を公表。 ',
            'needs_human_review' => false,
        ]);

        $this->assertSame('株式会社サンプル', $analysis->organization);
        $this->assertNull($analysis->incidentTitle);
        $this->assertSame(['氏名', 'メールアドレス'], $analysis->dataTypes);
        $this->assertNull($analysis->responseStatus);
        $this->assertSame('不正アクセスの可能性を公表。', $analysis->summary);
        $this->assertTrue($analysis->needsHumanReview);
        $this->assertSame('約1,600件', $analysis->jsonSerialize()['affected_count_text']);
    }

    public function test_from_array_rejects_a_missing_summary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IncidentAnalysis::fromArray(['data_types' => []]);
    }

    public function test_from_array_rejects_non_list_data_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IncidentAnalysis::fromArray(['summary' => 'x', 'data_types' => ['a' => '氏名']]);
    }

    public function test_mock_never_claims_to_have_analyzed_the_announcement(): void
    {
        $result = (new MockIncidentAnalyzer)->analyze(
            'https://example.invalid/notice',
            '公式発表',
            '160万件の情報が漏えいした可能性があります。',
        );

        $this->assertSame('mock', $result->responseStatus);
        $this->assertSame('公式発表', $result->incidentTitle);
        $this->assertNull($result->affectedCountText);
        $this->assertSame([], $result->dataTypes);
        $this->assertTrue($result->needsHumanReview);
    }
}
