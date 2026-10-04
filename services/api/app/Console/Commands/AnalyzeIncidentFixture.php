<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\IncidentAnalyzer;
use Illuminate\Console\Command;
use Throwable;

final class AnalyzeIncidentFixture extends Command
{
    protected $signature = 'incidents:analyze-fixture
                            {path : UTF-8 text fixture path}
                            {--url=https://example.invalid/notice : Source URL recorded with the analysis}
                            {--title= : Source page title}';

    protected $description = 'Analyze a local incident notice fixture. AI_PROVIDER=sakura makes a real, billed request.';

    public function handle(IncidentAnalyzer $analyzer): int
    {
        $path = (string) $this->argument('path');
        $text = @file_get_contents($path);

        if (! is_string($text)) {
            $this->error('The fixture could not be read.');

            return self::FAILURE;
        }

        try {
            $analysis = $analyzer->analyze(
                (string) $this->option('url'),
                (string) $this->option('title'),
                $text,
            );
        } catch (Throwable $failure) {
            $this->error($failure::class.': '.$failure->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(
            $analysis,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
