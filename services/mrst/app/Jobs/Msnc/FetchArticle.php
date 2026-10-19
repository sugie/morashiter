<?php

namespace App\Jobs\Msnc;

use App\Exceptions\HttpFetchFailure;
use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlItem;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Models\SourceDocument;
use App\Services\Crawling\HostRateLimiter;
use App\Services\Crawling\MsncFetchGate;
use App\Services\Crawling\PythonArticleExtractor;
use App\Services\Crawling\SafeHttpFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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

            $containerSelector = $source->extraction_config['container_selector'] ?? null;
            $extraction = $extractor->extract(
                $adapterCode,
                $result->body,
                $result->finalUrl,
                is_string($containerSelector) && $containerSelector !== '' ? $containerSelector : null,
            );

            $contentHash = hash('sha256', $result->body);
            $urlHash = $claimed->url_hash;

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
                    'extraction_version' => $extraction['extraction_version'],
                ],
            );

            $this->recordOfficialLinkCandidates($document, $extraction['official_links']);

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

    /**
     * @param  list<array{url: string, text: string}>  $links
     */
    private function recordOfficialLinkCandidates(SourceDocument $document, array $links): void
    {
        foreach ($links as $link) {
            $scheme = parse_url($link['url'], PHP_URL_SCHEME);
            $host = parse_url($link['url'], PHP_URL_HOST);
            if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || strlen($link['url']) > 2048) {
                continue;
            }

            OfficialLinkCandidate::query()->firstOrCreate(
                ['source_document_id' => $document->id, 'url_hash' => hash('sha256', $link['url'])],
                [
                    'url' => $link['url'],
                    'host' => strtolower($host),
                    'anchor_text' => mb_substr($link['text'], 0, 200) ?: null,
                    'status' => OfficialDomain::query()->where('host', strtolower($host))->exists() ? 'accepted' : 'pending',
                ],
            );
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
