<?php

namespace Tests\Feature\Msic;

use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Services\Ai\AiUsageBudget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MsicRetryCommandsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.provider', 'sakura');
        Config::set('ai.max_analyses_per_day', 20);
        Config::set('ai.max_attempts_per_document', 3);
        Queue::fake();
    }

    private function review(string $status, ?string $errorCode, int $attempts, string $analyzer = 'sakura'): IncidentReview
    {
        return IncidentReview::query()->create([
            'official_document_id' => OfficialDocument::factory()->create()->id,
            'status' => $status, 'analyzer' => $analyzer, 'error_code' => $errorCode,
            'attempts' => $attempts, 'analyzed_at' => now(),
        ]);
    }

    public function test_analyze_pending_queues_retryable_failures_but_not_exhausted_or_permanent_ones(): void
    {
        $retryable = $this->review('analysis_failed', 'ai_failed', 1);
        $this->review('analysis_failed', 'ai_failed', 3);
        $this->review('analysis_failed', 'ai_rejected', 1);
        $this->review('analysis_failed', 'pdf_text_unsupported', 0, 'mock');
        $this->review('analysis_failed', 'no_text', 1);
        $this->review('approved', null, 1);

        $this->artisan('msic:analyze-pending')->assertExitCode(0);

        Queue::assertPushed(AnalyzeOfficialDocument::class, 1);
        Queue::assertPushed(AnalyzeOfficialDocument::class, fn ($job): bool => $job->officialDocumentId === $retryable->official_document_id);
    }

    public function test_dry_run_lists_what_would_be_queued_without_queueing(): void
    {
        $retryable = $this->review('analysis_failed', 'ai_failed', 1);
        $unreviewed = OfficialDocument::factory()->create();

        $this->artisan('msic:analyze-pending', ['--dry-run' => true])
            ->expectsOutputToContain("document {$unreviewed->id}: unreviewed")
            ->expectsOutputToContain("document {$retryable->official_document_id}: retry (attempt 2 of 3)")
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_requeue_review_makes_a_failed_review_eligible_again(): void
    {
        $exhausted = $this->review('analysis_failed', 'ai_failed', 3);
        $rejected = $this->review('analysis_failed', 'ai_rejected', 1);

        $this->artisan('msic:requeue-review', ['review' => $exhausted->id])->assertExitCode(0);
        $this->artisan('msic:requeue-review', ['review' => $rejected->id])->assertExitCode(0);

        $this->assertSame([0, 'ai_failed'], [$exhausted->fresh()->attempts, $exhausted->fresh()->error_code]);
        $this->assertSame([0, 'ai_failed'], [$rejected->fresh()->attempts, $rejected->fresh()->error_code]);
        Queue::assertNothingPushed();

        $this->artisan('msic:analyze-pending')->assertExitCode(0);
        Queue::assertPushed(AnalyzeOfficialDocument::class, 2);
    }

    public function test_requeue_review_refuses_reviews_that_cannot_be_analysed_again(): void
    {
        $pdf = $this->review('analysis_failed', 'pdf_text_unsupported', 0, 'mock');
        $approved = $this->review('approved', null, 1);

        $this->artisan('msic:requeue-review', ['review' => $pdf->id])->assertExitCode(1);
        $this->artisan('msic:requeue-review', ['review' => $approved->id])->assertExitCode(1);
        $this->artisan('msic:requeue-review', ['review' => 99999])->assertExitCode(1);

        $this->assertSame('pdf_text_unsupported', $pdf->fresh()->error_code);
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_retries_are_queued_before_new_work_when_the_batch_is_limited(): void
    {
        Config::set('ai.max_analyses_per_day', 1);
        OfficialDocument::factory()->count(2)->create();
        $retry = $this->review('analysis_failed', 'ai_failed', 1);

        $this->artisan('msic:analyze-pending')->assertExitCode(0);

        Queue::assertPushed(AnalyzeOfficialDocument::class, 1);
        Queue::assertPushed(AnalyzeOfficialDocument::class, fn ($job): bool => $job->officialDocumentId === $retry->official_document_id);
    }

    public function test_dry_run_reports_how_much_of_the_daily_cap_is_used(): void
    {
        Config::set('ai.max_analyses_per_day', 3);
        app(AiUsageBudget::class)->reserve('sakura');

        $this->artisan('msic:analyze-pending', ['--dry-run' => true])
            ->expectsOutputToContain('daily cap (sakura): 1 of 3 used')
            ->assertExitCode(0);
    }
}
