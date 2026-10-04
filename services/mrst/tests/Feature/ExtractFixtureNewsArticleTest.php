<?php

namespace Tests\Feature;

use App\Jobs\Msnc\ExtractFixtureNewsArticle;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Models\SourceDocument;
use App\Services\Crawling\PythonArticleExtractor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ExtractFixtureNewsArticleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_fixture_job_extracts_and_stores_private_evidence_in_postgresql(): void
    {
        Storage::fake('local');
        $source = CrawlSource::factory()->create();
        $run = CrawlRun::factory()->for($source, 'source')->create();

        (new ExtractFixtureNewsArticle($run->id))->handle(app(PythonArticleExtractor::class));

        $document = SourceDocument::query()->sole();
        $this->assertSame('テスト企業、システム障害について発表', $document->title);
        $this->assertSame('2026-10-02', $document->published_on->toDateString());
        $this->assertSame('fixture_news_v1', $document->extraction_version);
        $this->assertSame('succeeded', $run->fresh()->status);
        Storage::disk('local')->assertExists($document->private_object_key);
        $evidence = json_decode(Storage::disk('local')->get($document->private_object_key), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("テスト企業は、システム障害を公表しました。\n影響範囲は現在調査中です。", $evidence['extraction']['body_text']);
    }

    public function test_repeated_fixture_run_does_not_duplicate_a_source_snapshot(): void
    {
        Storage::fake('local');
        $source = CrawlSource::factory()->create();
        $firstRun = CrawlRun::factory()->for($source, 'source')->create();
        $secondRun = CrawlRun::factory()->for($source, 'source')->create();
        $extractor = app(PythonArticleExtractor::class);

        (new ExtractFixtureNewsArticle($firstRun->id))->handle($extractor);
        (new ExtractFixtureNewsArticle($secondRun->id))->handle($extractor);

        $this->assertSame(1, SourceDocument::query()->count());
        $this->assertSame('succeeded', $secondRun->fresh()->status);
    }

    public function test_input_larger_than_two_mib_is_rejected_before_process_start(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extractor input exceeds 2 MiB.');

        app(PythonArticleExtractor::class)->extract('fixture_news', str_repeat('x', 2 * 1024 * 1024), 'https://news.example.test/incidents/sample');
    }

    public function test_unknown_adapter_fails_without_a_successful_extraction(): void
    {
        $html = file_get_contents('/opt/msnc/tests/fixtures/sample_news.html');
        $this->assertIsString($html);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extractor returned a failure.');

        app(PythonArticleExtractor::class)->extract('unknown', $html, 'https://news.example.test/incidents/sample');
    }

    public function test_job_is_dispatched_to_msnc_database_queue(): void
    {
        $source = CrawlSource::factory()->create();
        $run = CrawlRun::factory()->for($source, 'source')->create();
        config()->set('queue.default', 'database');

        ExtractFixtureNewsArticle::dispatch($run->id);

        $this->assertDatabaseHas('jobs', ['queue' => 'msnc']);
    }

    public function test_invalid_source_fails_without_creating_a_document(): void
    {
        $source = CrawlSource::factory()->create(['allowed_hosts' => ['other.example.test']]);
        $run = CrawlRun::factory()->for($source, 'source')->create();

        try {
            (new ExtractFixtureNewsArticle($run->id))->handle(app(PythonArticleExtractor::class));
            $this->fail('The invalid source should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture source configuration is invalid.', $exception->getMessage());
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, SourceDocument::query()->count());
    }
}
