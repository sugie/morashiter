<?php

namespace App\Services\Ai;

class MockIncidentAnalyzer implements IncidentAnalyzer
{
    public function analyze(string $sourceUrl, string $title, string $text): IncidentAnalysis
    {
        return new IncidentAnalysis(
            organization: null,
            incidentTitle: $title !== '' ? $title : null,
            affectedCountText: null,
            dataTypes: [],
            disclosedAt: null,
            responseStatus: 'mock',
            summary: 'AI_PROVIDER=mockのため、外部AIへの送信と自動抽出は行っていません。',
        );
    }
}
