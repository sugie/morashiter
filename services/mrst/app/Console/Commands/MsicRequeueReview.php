<?php

namespace App\Console\Commands;

use App\Models\IncidentReview;
use Illuminate\Console\Command;

/**
 * Makes an AI-failed review eligible again (attempts reset). It does not call the AI itself;
 * msic:analyze-pending picks it up within the daily cap.
 */
class MsicRequeueReview extends Command
{
    protected $signature = 'msic:requeue-review {review : incident_reviews id}';

    protected $description = 'Reset an AI-failed review so the next analysis run retries it.';

    public function handle(): int
    {
        $review = IncidentReview::query()->find((int) $this->argument('review'));
        if ($review === null) {
            $this->error('Review not found.');

            return self::FAILURE;
        }
        if ($review->status !== 'analysis_failed' || ! in_array($review->error_code, ['ai_failed', 'ai_rejected', 'pdf_text_unsupported', 'no_text'], true)) {
            $this->error("Review #{$review->id} ({$review->status}/{$review->error_code}) cannot be analysed again.");

            return self::FAILURE;
        }

        $review->update(['attempts' => 0, 'error_code' => 'ai_failed']);
        $this->info("Review #{$review->id} will be retried by the next msic:analyze-pending run (within the daily cap).");

        return self::SUCCESS;
    }
}
