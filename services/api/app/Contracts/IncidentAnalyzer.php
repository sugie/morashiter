<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\IncidentAnalysis;

interface IncidentAnalyzer
{
    public function analyze(string $sourceUrl, string $title, string $text): IncidentAnalysis;
}
