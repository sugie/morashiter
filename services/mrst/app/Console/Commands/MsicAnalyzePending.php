<?php

namespace App\Console\Commands;

use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Models\OfficialDocument;
use App\Services\Ai\AiUsageBudget;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MsicAnalyzePending extends Command
{
    protected $signature = 'msic:analyze-pending
                            {--dry-run : List what would be queued without queueing}';

    protected $description = 'Queue analysis for retryable AI failures first, then documents without a review or with only a mock draft.';

    public function handle(AiUsageBudget $budget): int
    {
        $provider = (string) config('ai.provider', 'mock');
        $maxAttempts = (int) config('ai.max_attempts_per_document', 3);
        $limit = max(1, (int) config('ai.max_analyses_per_day', 20));

        $documents = $this->retries($provider, $maxAttempts)
            ->concat($this->newWork($provider))
            ->take($limit);

        if ($this->option('dry-run') && $provider !== 'mock') {
            $this->line("daily cap ({$provider}): {$budget->usedToday($provider)} of ".config('ai.max_analyses_per_day').' used');
        }
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

    /**
     * @return Collection<int, OfficialDocument>
     */
    private function retries(string $provider, int $maxAttempts): Collection
    {
        if ($provider === 'mock') {
            return collect();
        }

        return OfficialDocument::query()
            ->with('review')
            ->whereHas('review', fn (Builder $review) => $review->where('status', 'analysis_failed')
                ->where('error_code', 'ai_failed')
                ->where('attempts', '<', $maxAttempts))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, OfficialDocument>
     */
    private function newWork(string $provider): Collection
    {
        return OfficialDocument::query()
            ->with('review')
            ->where(function (Builder $query) use ($provider): void {
                $query->whereDoesntHave('review');
                if ($provider !== 'mock') {
                    $query->orWhereHas('review', fn (Builder $review) => $review->where('analyzer', 'mock')->where('status', 'pending_review'));
                }
            })
            ->orderBy('id')
            ->get();
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
