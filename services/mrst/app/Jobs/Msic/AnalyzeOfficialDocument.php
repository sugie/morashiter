<?php

namespace App\Jobs\Msic;

use App\Exceptions\AiAnalysisFailure;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Services\Ai\IncidentAnalyzer;
use App\Services\Crawling\OfficialDocumentText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an official announcement into an AI draft for human review. Only companies' own
 * documents are sent; news text never reaches here. Real providers are capped per day.
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

    public function handle(IncidentAnalyzer $analyzer, OfficialDocumentText $text): void
    {
        $document = OfficialDocument::query()->with('review')->find($this->officialDocumentId);
        if ($document === null) {
            return;
        }

        $provider = (string) config('ai.provider', 'mock');
        $review = $document->review;
        $replacesMockDraft = $review !== null && $review->analyzer === 'mock' && $review->status === 'pending_review' && $provider !== 'mock';
        if ($review !== null && ! $replacesMockDraft) {
            return;
        }
        if ($provider !== 'mock' && $this->analysesToday($provider) >= (int) config('ai.max_analyses_per_day', 20)) {
            return;
        }

        $fields = $this->analyze($document, $provider, $analyzer, $text);
        IncidentReview::query()->updateOrCreate(
            ['official_document_id' => $document->id],
            $fields + ['analyzer' => $provider, 'analyzed_at' => now()],
        );
    }

    /**
     * @return array{status: string, analysis: ?array<string, mixed>, error_code: ?string}
     */
    private function analyze(OfficialDocument $document, string $provider, IncidentAnalyzer $analyzer, OfficialDocumentText $text): array
    {
        if ($document->content_type === 'application/pdf') {
            return ['status' => 'analysis_failed', 'analysis' => null, 'error_code' => 'pdf_text_unsupported'];
        }

        $body = $text->fromHtml((string) Storage::disk('local')->get($document->private_object_key));
        if ($body === '') {
            return ['status' => 'analysis_failed', 'analysis' => null, 'error_code' => 'no_text'];
        }

        try {
            $analysis = $analyzer->analyze($document->url, (string) $document->title, $body);
        } catch (AiAnalysisFailure) {
            return ['status' => 'analysis_failed', 'analysis' => null, 'error_code' => 'ai_failed'];
        }

        return ['status' => 'pending_review', 'analysis' => $analysis->jsonSerialize(), 'error_code' => null];
    }

    private function analysesToday(string $provider): int
    {
        return IncidentReview::query()
            ->where('analyzer', $provider)
            ->where('analyzed_at', '>=', now()->startOfDay())
            ->count();
    }
}
