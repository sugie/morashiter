<?php

namespace App\Console\Commands;

use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Models\OfficialDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MsicAnalyzePending extends Command
{
    protected $signature = 'msic:analyze-pending';

    protected $description = 'Queue analysis for official documents without a review, or with only a mock draft.';

    public function handle(): int
    {
        $ids = OfficialDocument::query()
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('review')
                    ->orWhereHas('review', fn (Builder $review) => $review->where('analyzer', 'mock')->where('status', 'pending_review'));
            })
            ->orderBy('id')
            ->limit(max(1, (int) config('ai.max_analyses_per_day', 20)))
            ->pluck('id');

        foreach ($ids as $id) {
            AnalyzeOfficialDocument::dispatch($id);
        }
        $this->info("Queued {$ids->count()} official document analyses.");

        return self::SUCCESS;
    }
}
