<?php

namespace App\Jobs\Msnc;

use App\Exceptions\HttpFetchFailure;
use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlItem;
use App\Models\SourceDocument;
use App\Services\Crawling\HostRateLimiter;
use App\Services\Crawling\MsncFetchGate;
use App\Services\Crawling\PythonArticleExtractor;
use App\Services\Crawling\SafeHttpFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FetchArticle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public int $crawlItemId)
    {
        $this->onQueue('msnc');
    }

    public function handle(
        SafeHttpFetcher $fetcher,
        PythonArticleExtractor $extractor,
        HostRateLimiter $rateLimiter,
    ): void {
        $closedReason = app(MsncFetchGate::class)->closedReason();
        if ($closedReason !== null) {
            CrawlItem::query()
                ->whereKey($this->crawlItemId)
                ->where('status', 'queued')
                ->update(['status' => 'skipped', 'error_code' => $closedReason, 'finished_at' => now()]);

            return;
        }

        $claimed = DB::transaction(function () {
            $item = CrawlItem::query()
                ->whereKey($this->crawlItemId)
                ->lockForUpdate()
                ->first();
            if ($item === null || $item->status !== 'queued') {
                return null;
            }
            $item->update([
                'status' => 'running',
                'attempts' => $item->attempts + 1,
            ]);

            return $item->fresh();
        });

        if ($claimed === null) {
            return;
        }

        $source = $claimed->source;
        $adapterCode = $source->extraction_config['adapter_code'] ?? null;
        if (! is_string($adapterCode) || $adapterCode === '') {
            $this->markFailed($claimed, 'adapter_missing');

            return;
        }

        try {
            $host = parse_url($claimed->normalized_url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $rateLimiter->throttle($host);
            }

            $result = $fetcher->fetch($claimed->normalized_url, $source);
            if ($result->status !== 200) {
                $this->markFailed($claimed, 'http_non_200');

                return;
            }

            $extraction = $extractor->extract($adapterCode, $result->body, $result->finalUrl);

            $contentHash = hash('sha256', $result->body);
            $urlHash = $claimed->url_hash;
            $privateKey = sprintf('msnc/evidence/%d/%s/%s.json', $source->id, $urlHash, $contentHash);

            $evidencePayload = json_encode(
                [
                    'html' => $result->body,
                    'extraction' => $extraction,
                    'final_url' => $result->finalUrl,
                    'fetched_at' => now()->toIso8601String(),
                    'http_status' => $result->status,
                ],
                JSON_THROW_ON_ERROR,
            );

            if (! Storage::disk('local')->put($privateKey, $evidencePayload)) {
                throw new RuntimeException('Private evidence could not be stored.');
            }

            $document = SourceDocument::query()->firstOrCreate(
                [
                    'source_id' => $source->id,
                    'url_hash' => $urlHash,
                    'content_sha256' => $contentHash,
                ],
                [
                    'crawl_run_id' => $claimed->crawl_run_id,
                    'canonical_url' => $claimed->normalized_url,
                    'final_url' => $result->finalUrl,
                    'title' => $extraction['title'],
                    'published_on' => $extraction['published_on'],
                    'fetched_at' => now(),
                    'http_status' => $result->status,
                    'content_type' => $result->header('Content-Type') ?? 'text/html',
                    'private_object_key' => $privateKey,
                    'extraction_version' => $extraction['extraction_version'],
                ],
            );

            $claimed->update([
                'status' => 'succeeded',
                'source_document_id' => $document->id,
                'finished_at' => now(),
            ]);
        } catch (UrlPolicyViolation) {
            $this->markFailed($claimed, 'url_policy_violation');
        } catch (HttpFetchFailure $e) {
            $this->markFailed($claimed, $e->failureCode);
        } catch (Throwable $e) {
            $this->markFailed($claimed, 'extraction_failed');
            throw $e;
        }
    }

    private function markFailed(CrawlItem $item, string $code): void
    {
        $item->update([
            'status' => 'failed',
            'error_code' => $code,
            'finished_at' => now(),
        ]);
    }
}
