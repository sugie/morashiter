<?php

namespace App\Jobs\Msnc;

use App\Models\CrawlItem;
use App\Models\CrawlRun;
use App\Models\CrawlSource;
use App\Services\Crawling\MsncFetchGate;
use App\Services\Crawling\RssAtomAdapter;
use App\Services\Crawling\SafeHttpFetcher;
use App\Services\Crawling\UrlPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class DiscoverNews implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    private const MAX_FAILED_ATTEMPTS = 3;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('msnc');
    }

    public function handle(SafeHttpFetcher $fetcher, RssAtomAdapter $adapter, UrlPolicy $urlPolicy): void
    {
        if (app(MsncFetchGate::class)->closedReason() !== null) {
            return;
        }

        $source = CrawlSource::query()->findOrFail($this->sourceId);

        if (! $source->is_enabled || $source->crawler_code !== 'msnc' || $source->source_type !== 'news') {
            throw new RuntimeException(
                "Source {$source->id} is not an enabled msnc news source.",
            );
        }

        $jobKey = sprintf(
            'msnc:discover:%d:%s:%s',
            $source->id,
            now()->format('YmdHis'),
            bin2hex(random_bytes(4)),
        );

        $run = CrawlRun::query()->create([
            'source_id' => $source->id,
            'job_key' => $jobKey,
            'status' => 'running',
            'started_at' => now(),
            'attempts' => 1,
        ]);

        try {
            $result = $fetcher->fetch($source->base_url, $source, [
                'accept' => 'application/atom+xml, application/rss+xml, application/xml;q=0.9',
            ]);
            if ($result->status !== 200) {
                throw new RuntimeException("Feed returned HTTP {$result->status}.");
            }

            $articles = $adapter->parse($result->body);
            $maxItems = (int) config('crawler.max_items_per_run', 100);
            $created = 0;
            $skipped = 0;

            foreach (array_slice($articles, 0, $maxItems) as $article) {
                $normalized = $urlPolicy->validate($article->url, $source);
                $urlHash = hash('sha256', $normalized);

                if ($this->isAlreadyHandled($source->id, $urlHash)) {
                    $skipped++;

                    continue;
                }

                $item = CrawlItem::query()->firstOrCreate(
                    [
                        'crawl_run_id' => $run->id,
                        'url_hash' => $urlHash,
                    ],
                    [
                        'source_id' => $source->id,
                        'normalized_url' => $normalized,
                        'status' => 'queued',
                        'attempts' => 0,
                        'discovered_at' => now(),
                    ],
                );
                $created++;
                if ($item->wasRecentlyCreated) {
                    FetchArticle::dispatch($item->id);
                }
            }

            $run->update([
                'status' => 'succeeded',
                'finished_at' => now(),
                'discovery_finished_at' => now(),
                'discovered_count' => $created,
                'skipped_count' => $skipped,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_code' => 'discovery_failed',
                'error_summary' => substr($exception->getMessage(), 0, 500),
            ]);
            throw $exception;
        }
    }

    /**
     * A URL is not fetched again once it succeeded, while it is still pending,
     * or after it has failed MAX_FAILED_ATTEMPTS times for this source.
     */
    private function isAlreadyHandled(int $sourceId, string $urlHash): bool
    {
        $statuses = CrawlItem::query()
            ->where('source_id', $sourceId)
            ->where('url_hash', $urlHash)
            ->pluck('status');

        return $statuses->intersect(['queued', 'running', 'succeeded'])->isNotEmpty()
            || $statuses->filter(fn (string $status): bool => $status === 'failed')->count() >= self::MAX_FAILED_ATTEMPTS;
    }
}
