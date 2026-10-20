<?php

namespace App\Jobs\Msic;

use App\Exceptions\AiAnalysisFailure;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Services\Ai\AiUsageBudget;
use App\Services\Ai\IncidentAnalyzer;
use App\Services\Crawling\OfficialDocumentText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an official announcement into an AI draft for human review. Only companies' own
 * documents are sent; news text never reaches here.
 *
 * Status transitions: none/mock draft -> pending_review | analysis_failed.
 * analysis_failed with ai_failed is retried until ai.max_attempts_per_document real calls;
 * ai_rejected, pdf_text_unsupported and no_text are not retried. Every real call is reserved
 * against the daily cap first; when the cap is used up the document waits for the next day.
 */
class AnalyzeOfficialDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 100;

    public int $tries = 1;

    public function __construct(public int $officialDocumentId)
    {
        $this->onQueue('msnc');
    }

    public function handle(IncidentAnalyzer $analyzer, OfficialDocumentText $text, AiUsageBudget $budget): void
    {
        $document = OfficialDocument::query()->with('review')->find($this->officialDocumentId);
        if ($document === null) {
            return;
        }

        $provider = (string) config('ai.provider', 'mock');
        $review = $document->review;
        if (! self::isEligible($review, $provider)) {
            return;
        }

        if ($document->content_type === 'application/pdf') {
            $this->record($document, $provider, $review?->attempts ?? 0, 'analysis_failed', null, 'pdf_text_unsupported');

            return;
        }
        $body = $text->fromHtml((string) Storage::disk('local')->get($document->private_object_key));
        if ($body === '') {
            $this->record($document, $provider, $review?->attempts ?? 0, 'analysis_failed', null, 'no_text');

            return;
        }

        $attempts = $review?->attempts ?? 0;
        if ($provider !== 'mock') {
            if (! $budget->reserve($provider)) {
                return;
            }
            $attempts++;
        }

        try {
            $analysis = $analyzer->analyze($document->url, (string) $document->title, $body);
        } catch (AiAnalysisFailure $failure) {
            $this->record($document, $provider, $attempts, 'analysis_failed', null, $failure->retryable ? 'ai_failed' : 'ai_rejected');

            return;
        }

        $this->record($document, $provider, $attempts, 'pending_review', $analysis->jsonSerialize(), null);
    }

    public static function isEligible(?IncidentReview $review, string $provider): bool
    {
        if ($review === null) {
            return true;
        }
        if ($provider === 'mock') {
            return false;
        }
        if ($review->status === 'pending_review' && $review->analyzer === 'mock') {
            return true;
        }

        return $review->status === 'analysis_failed'
            && $review->error_code === 'ai_failed'
            && $review->attempts < (int) config('ai.max_attempts_per_document', 3);
    }

    /**
     * @param  array<string, mixed>|null  $analysis
     */
    private function record(OfficialDocument $document, string $provider, int $attempts, string $status, ?array $analysis, ?string $errorCode): void
    {
        IncidentReview::query()->updateOrCreate(
            ['official_document_id' => $document->id],
            [
                'status' => $status,
                'analyzer' => $provider,
                'analysis' => $analysis,
                'error_code' => $errorCode,
                'attempts' => $attempts,
                'analyzed_at' => now(),
            ],
        );
    }
}
