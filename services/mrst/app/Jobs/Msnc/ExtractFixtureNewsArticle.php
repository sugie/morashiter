<?php

namespace App\Jobs\Msnc;

use App\Models\CrawlRun;
use App\Models\SourceDocument;
use App\Services\Crawling\PythonArticleExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExtractFixtureNewsArticle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(public int $crawlRunId)
    {
        $this->onQueue('msnc');
    }

    public function handle(PythonArticleExtractor $extractor): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Fixture extraction is restricted to local and testing environments.');
        }

        $run = CrawlRun::query()->findOrFail($this->crawlRunId);
        $source = $run->source;
        $run->update(['status' => 'running', 'started_at' => now(), 'attempts' => $run->attempts + 1]);
        try {
            if (! $source->is_enabled || $source->crawler_code !== 'msnc'
                || $source->source_type !== 'news'
                || ($source->extraction_config['adapter_code'] ?? null) !== 'fixture_news'
                || parse_url($source->base_url, PHP_URL_SCHEME) !== 'https'
                || ! in_array(parse_url($source->base_url, PHP_URL_HOST), $source->allowed_hosts, true)) {
                throw new RuntimeException('Fixture source configuration is invalid.');
            }

            $html = file_get_contents('/opt/msnc/tests/fixtures/sample_news.html');
            if ($html === false) {
                throw new RuntimeException('Fixture could not be read.');
            }
            $result = $extractor->extract('fixture_news', $html, $source->base_url);
            $contentHash = hash('sha256', $html);
            $urlHash = hash('sha256', $source->base_url);
            $key = "msnc/evidence/{$source->id}/{$urlHash}/{$contentHash}.json";
            $evidence = json_encode(['html' => $html, 'extraction' => $result], JSON_THROW_ON_ERROR);
            if (! Storage::disk('local')->put($key, $evidence)) {
                throw new RuntimeException('Private evidence could not be stored.');
            }

            SourceDocument::query()->firstOrCreate(
                ['source_id' => $source->id, 'url_hash' => $urlHash, 'content_sha256' => $contentHash],
                [
                    'crawl_run_id' => $run->id,
                    'canonical_url' => $source->base_url,
                    'final_url' => $source->base_url,
                    'title' => $result['title'],
                    'published_on' => $result['published_on'],
                    'fetched_at' => now(),
                    'http_status' => 200,
                    'content_type' => 'text/html',
                    'private_object_key' => $key,
                    'extraction_version' => $result['extraction_version'],
                ],
            );
            $run->update(['status' => 'succeeded', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_code' => 'fixture_extraction_failed',
                'error_summary' => 'Fixture extraction or persistence failed.',
            ]);
            throw $exception;
        }
    }
}
