<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ai;

use App\Infrastructure\Ai\MockIncidentAnalyzer;
use PHPUnit\Framework\TestCase;

final class MockIncidentAnalyzerTest extends TestCase
{
    public function test_it_never_claims_to_have_analyzed_the_notice(): void
    {
        $result = (new MockIncidentAnalyzer)->analyze(
            'https://example.invalid/notice',
            '公式発表',
            '160万件の情報が漏えいした可能性があります。',
        );

        self::assertSame('mock', $result->responseStatus);
        self::assertSame([], $result->dataTypes);
        self::assertTrue($result->needsHumanReview);
    }
}
