<?php

namespace App\Console\Commands;

use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Models\OfficialDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MsicAnalyzePending extends Command
{
    protected $signature = 'msic:analyze-pending
                            {--dry-run : List what would be queued without queueing}';

    protected $description = 'Queue analysis for official documents without a review, with only a mock draft, or with a retryable AI failure.';

    public function handle(): int
    {
        $provider = (string) config('ai.provider', 'mock');
        $maxAttempts = (int) config('ai.max_attempts_per_document', 3);

        $documents = OfficialDocument::query()
            ->with('review')
            ->where(function (Builder $query) use ($provider, $maxAttempts): void {
                $query->whereDoesntHave('review');
                if ($provider !== 'mock') {
                    $query->orWhereHas('review', fn (Builder $review) => $review->where('analyzer', 'mock')->where('status', 'pending_review'))
                        ->orWhereHas('review', fn (Builder $review) => $review->where('status', 'analysis_failed')
                            ->where('error_code', 'ai_failed')
                            ->where('attempts', '<', $maxAttempts));
                }
            })
            ->orderBy('id')
            ->limit(max(1, (int) config('ai.max_analyses_per_day', 20)))
            ->get();

        foreach ($documents as $document) {
            if ($this->option('dry-run')) {
                $this->line("document {$document->id}: ".$this->reason($document, $maxAttempts));

                continue;
            }
            AnalyzeOfficialDocument::dispatch($document->id);
        }

        $this->info(($this->option('dry-run') ? 'Would queue ' : 'Queued ')."{$documents->count()} official document analyses.");

        return self::SUCCESS;
    }

    private function reason(OfficialDocument $document, int $maxAttempts): string
    {
        $review = $document->review;

        return match (true) {
            $review === null => 'unreviewed',
            $review->status === 'analysis_failed' => 'retry (attempt '.($review->attempts + 1)." of {$maxAttempts})",
            default => 'mock draft',
        };
    }
}
