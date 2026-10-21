<?php

namespace Tests\Feature\Msic;

use App\Exceptions\AiAnalysisFailure;
use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Services\Ai\IncidentAnalysis;
use App\Services\Ai\IncidentAnalyzer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class AnalyzeOfficialDocumentTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<array{url: string, title: string, text: string}> */
    private array $calls = [];

    private bool $failNext = false;

    private bool $failRetryable = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('ai.provider', 'sakura');
        Config::set('ai.max_analyses_per_day', 20);
        Config::set('ai.max_attempts_per_document', 3);
        Config::set('ai.budget_timezone', 'Asia/Tokyo');
        $test = $this;
        $this->app->instance(IncidentAnalyzer::class, new class($test) implements IncidentAnalyzer
        {
            public function __construct(private AnalyzeOfficialDocumentTest $test) {}

            public function analyze(string $sourceUrl, string $title, string $text): IncidentAnalysis
            {
                return $this->test->recordCall($sourceUrl, $title, $text);
            }
        });
    }

    public function recordCall(string $url, string $title, string $text): IncidentAnalysis
    {
        if ($this->failNext) {
            throw new AiAnalysisFailure('provider_error', retryable: $this->failRetryable);
        }
        $this->calls[] = compact('url', 'title', 'text');

        return IncidentAnalysis::fromArray([
            'organization' => 'テスト株式会社',
            'incident_title' => '不正アクセスによる個人情報流出',
            'affected_count_text' => '約1,200件',
            'data_types' => ['氏名', 'メールアドレス'],
            'disclosed_at' => '2026-10-19',
            'response_status' => '調査中',
            'summary' => 'テスト株式会社は不正アクセスにより約1,200件の個人情報が流出した可能性を公表した。',
        ]);
    }

    private function document(string $body, string $type = 'text/html'): OfficialDocument
    {
        $document = OfficialDocument::factory()->create(['content_type' => $type]);
        Storage::disk('local')->put($document->private_object_key, $body);

        return $document;
    }

    private function analyze(OfficialDocument $document): void
    {
        app()->call([new AnalyzeOfficialDocument($document->id), 'handle']);
    }

    public function test_html_announcement_is_analyzed_into_a_pending_review(): void
    {
        $document = $this->document('<html><head><title>お詫び</title><script>var x=1;</script></head><body><nav>メニュー</nav><main><h1>不正アクセスに関するお詫び</h1><p>当社は不正アクセスを受けました。</p></main><footer>Copyright</footer></body></html>');

        $this->analyze($document);

        $review = IncidentReview::query()->sole();
        $this->assertSame($document->id, $review->official_document_id);
        $this->assertSame('pending_review', $review->status);
        $this->assertSame('sakura', $review->analyzer);
        $this->assertSame('約1,200件', $review->analysis['affected_count_text']);
        $this->assertTrue($review->analysis['needs_human_review']);
        $this->assertNotNull($review->analyzed_at);
        $this->assertSame($document->url, $this->calls[0]['url']);
        $this->assertSame("不正アクセスに関するお詫び\n当社は不正アクセスを受けました。", $this->calls[0]['text']);
    }

    public function test_page_chrome_and_scripts_never_reach_the_analyzer(): void
    {
        $this->analyze($this->document('<html><body><header>ヘッダー</header><article><p>本文</p></article><aside>広告</aside><style>p{}</style></body></html>'));

        $this->assertSame('本文', $this->calls[0]['text']);
    }

    public function test_pdf_announcement_is_analyzed_into_a_pending_review(): void
    {
        $pdf = PdfFixture::withText(['Morashiter PDF sample', 'Hello world']);

        $this->analyze($this->document($pdf, 'application/pdf'));

        $review = IncidentReview::query()->sole();
        $this->assertSame('pending_review', $review->status);
        $this->assertSame('sakura', $review->analyzer);
        $this->assertStringContainsString('Morashiter PDF sample', $this->calls[0]['text']);
        $this->assertStringContainsString('Hello world', $this->calls[0]['text']);
    }

    public function test_pdf_without_text_falls_into_no_text(): void
    {
        $this->analyze($this->document(PdfFixture::withoutText(), 'application/pdf'));

        $review = IncidentReview::query()->sole();
        $this->assertSame('analysis_failed', $review->status);
        $this->assertSame('no_text', $review->error_code);
        $this->assertSame([], $this->calls);
    }

    public function test_document_without_text_is_not_sent(): void
    {
        $this->analyze($this->document('<html><body><nav>メニューのみ</nav></body></html>'));

        $this->assertSame('no_text', IncidentReview::query()->sole()->error_code);
        $this->assertSame([], $this->calls);
    }

    public function test_analyzer_failure_is_recorded_without_throwing(): void
    {
        $this->failNext = true;

        $this->analyze($this->document('<html><body><p>本文</p></body></html>'));

        $review = IncidentReview::query()->sole();
        $this->assertSame('analysis_failed', $review->status);
        $this->assertSame('ai_failed', $review->error_code);
    }

    public function test_an_already_analyzed_document_is_not_sent_again(): void
    {
        $document = $this->document('<html><body><p>本文</p></body></html>');

        $this->analyze($document);
        $this->analyze($document);

        $this->assertCount(1, $this->calls);
        $this->assertSame(1, IncidentReview::query()->count());
    }

    public function test_a_mock_review_is_reanalyzed_once_a_real_provider_is_configured(): void
    {
        $document = $this->document('<html><body><p>本文</p></body></html>');
        IncidentReview::query()->create([
            'official_document_id' => $document->id, 'status' => 'pending_review', 'analyzer' => 'mock',
            'analysis' => ['summary' => 'mock'], 'analyzed_at' => now(),
        ]);

        $this->analyze($document);

        $review = IncidentReview::query()->sole();
        $this->assertSame('sakura', $review->analyzer);
        $this->assertSame('約1,200件', $review->analysis['affected_count_text']);
    }

    public function test_daily_budget_stops_real_analyses_until_tomorrow(): void
    {
        Config::set('ai.max_analyses_per_day', 1);
        $first = $this->document('<html><body><p>一件目</p></body></html>');
        $second = $this->document('<html><body><p>二件目</p></body></html>');

        $this->analyze($first);
        $this->analyze($second);

        $this->assertCount(1, $this->calls);
        $this->assertSame(1, IncidentReview::query()->count());
        $this->assertNull($second->fresh()->review);
    }

    public function test_mock_provider_is_not_limited_by_the_budget(): void
    {
        Config::set('ai.provider', 'mock');
        Config::set('ai.max_analyses_per_day', 0);

        $this->analyze($this->document('<html><body><p>本文</p></body></html>'));

        $this->assertSame('mock', IncidentReview::query()->sole()->analyzer);
    }

    public function test_analyze_pending_command_queues_unreviewed_and_mock_reviewed_documents(): void
    {
        Queue::fake();
        $unreviewed = OfficialDocument::factory()->create();
        $mockReviewed = OfficialDocument::factory()->create();
        IncidentReview::query()->create(['official_document_id' => $mockReviewed->id, 'status' => 'pending_review', 'analyzer' => 'mock', 'analyzed_at' => now()]);
        $done = OfficialDocument::factory()->create();
        IncidentReview::query()->create(['official_document_id' => $done->id, 'status' => 'approved', 'analyzer' => 'sakura', 'analyzed_at' => now()]);

        $this->artisan('msic:analyze-pending')->assertExitCode(0);

        Queue::assertPushed(AnalyzeOfficialDocument::class, 2);
        Queue::assertPushed(AnalyzeOfficialDocument::class, fn ($job): bool => $job->officialDocumentId === $unreviewed->id);
        Queue::assertPushed(AnalyzeOfficialDocument::class, fn ($job): bool => $job->officialDocumentId === $mockReviewed->id);
    }

    private function failedReview(OfficialDocument $document, int $attempts, string $errorCode = 'ai_failed'): IncidentReview
    {
        return IncidentReview::query()->create([
            'official_document_id' => $document->id, 'status' => 'analysis_failed', 'analyzer' => 'sakura',
            'error_code' => $errorCode, 'attempts' => $attempts, 'analyzed_at' => now(),
        ]);
    }

    public function test_retryable_failure_is_recorded_as_ai_failed_with_one_attempt(): void
    {
        $this->failNext = true;

        $this->analyze($this->document('<html><body><p>本文</p></body></html>'));

        $review = IncidentReview::query()->sole();
        $this->assertSame(['analysis_failed', 'ai_failed', 1], [$review->status, $review->error_code, $review->attempts]);
    }

    public function test_non_retryable_failure_is_recorded_as_ai_rejected(): void
    {
        $this->failNext = true;
        $this->failRetryable = false;

        $this->analyze($this->document('<html><body><p>本文</p></body></html>'));

        $this->assertSame('ai_rejected', IncidentReview::query()->sole()->error_code);
    }

    public function test_a_failed_review_is_retried_and_can_succeed(): void
    {
        $document = $this->document('<html><body><p>本文</p></body></html>');
        $this->failedReview($document, attempts: 1);

        $this->analyze($document);

        $review = IncidentReview::query()->sole();
        $this->assertSame(['pending_review', null, 2], [$review->status, $review->error_code, $review->attempts]);
        $this->assertSame('約1,200件', $review->analysis['affected_count_text']);
    }

    public function test_the_last_allowed_retry_failing_stops_at_ai_failed(): void
    {
        $document = $this->document('<html><body><p>本文</p></body></html>');
        $this->failedReview($document, attempts: 2);
        $this->failNext = true;

        $this->analyze($document);
        $this->failNext = false;
        $this->analyze($document);

        $review = IncidentReview::query()->sole();
        $this->assertSame(['analysis_failed', 'ai_failed', 3], [$review->status, $review->error_code, $review->attempts]);
        $this->assertSame([], $this->calls);
    }

    public function test_rejected_and_unsupported_failures_are_not_retried(): void
    {
        $rejected = $this->document('<html><body><p>本文</p></body></html>');
        $this->failedReview($rejected, attempts: 1, errorCode: 'ai_rejected');
        $pdf = $this->document('%PDF', 'application/pdf');
        $this->failedReview($pdf, attempts: 0, errorCode: 'pdf_text_unsupported');

        $this->analyze($rejected);
        $this->analyze($pdf);

        $this->assertSame([], $this->calls);
    }

    public function test_every_real_call_counts_toward_the_daily_cap_including_retries(): void
    {
        Config::set('ai.max_analyses_per_day', 2);
        $first = $this->document('<html><body><p>一件目</p></body></html>');
        $second = $this->document('<html><body><p>二件目</p></body></html>');
        $this->failNext = true;

        $this->analyze($first);
        $this->analyze($first);
        $this->failNext = false;
        $this->analyze($second);

        $this->assertSame(2, IncidentReview::query()->where('official_document_id', $first->id)->value('attempts'));
        $this->assertNull($second->fresh()->review);
        $this->assertSame(2, (int) DB::table('ai_usage_days')->where('provider', 'sakura')->value('calls'));
    }

    public function test_the_daily_cap_resets_at_midnight_japan_time(): void
    {
        Config::set('ai.max_analyses_per_day', 1);
        $documents = [
            $this->document('<html><body><p>一</p></body></html>'),
            $this->document('<html><body><p>二</p></body></html>'),
            $this->document('<html><body><p>三</p></body></html>'),
        ];

        Carbon::setTestNow('2026-10-20 14:30:00 UTC'); // 23:30 JST on the 20th
        $this->analyze($documents[0]);
        Carbon::setTestNow('2026-10-20 14:55:00 UTC'); // 23:55 JST, same Japanese day
        $this->analyze($documents[1]);
        Carbon::setTestNow('2026-10-20 15:05:00 UTC'); // 00:05 JST on the 21st
        $this->analyze($documents[2]);
        Carbon::setTestNow();

        $this->assertCount(2, $this->calls);
        $this->assertNull($documents[1]->fresh()->review);
        $this->assertNotNull($documents[2]->fresh()->review);
    }

    public function test_mock_analyses_do_not_consume_the_cap(): void
    {
        Config::set('ai.provider', 'mock');

        $this->analyze($this->document('<html><body><p>本文</p></body></html>'));

        $this->assertSame(0, DB::table('ai_usage_days')->count());
    }
}
