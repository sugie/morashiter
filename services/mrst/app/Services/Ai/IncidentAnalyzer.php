<?php

namespace App\Services\Ai;

use App\Exceptions\AiAnalysisFailure;

interface IncidentAnalyzer
{
    /**
     * Analyze a company's own announcement. Never pass news article text here.
     *
     * @throws AiAnalysisFailure
     */
    public function analyze(string $sourceUrl, string $title, string $text): IncidentAnalysis;
}
