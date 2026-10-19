<?php

namespace Tests\Feature\Msic;

use App\Exceptions\AiAnalysisFailure;
use App\Jobs\Msic\AnalyzeOfficialDocument;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Services\Ai\IncidentAnalysis;
use App\Services\Ai\IncidentAnalyzer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyzeOfficialDocumentTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<array{url: string, title: string, text: string}> */
    private array $calls = [];

    private bool $failNext = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('ai.provider', 'sakura');
        Config::set('ai.max_analyses_per_day', 20);
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
            throw new AiAnalysisFailure('provider_error');
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

    public function test_pdf_is_recorded_as_not_yet_analyzable(): void
    {
        $this->analyze($this->document('%PDF-1.7', 'application/pdf'));

        $review = IncidentReview::query()->sole();
        $this->assertSame('analysis_failed', $review->status);
        $this->assertSame('pdf_text_unsupported', $review->error_code);
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
}
