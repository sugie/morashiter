<?php

namespace App\Console\Commands;

use App\Jobs\Msnc\DiscoverNews;
use App\Models\CrawlSource;
use App\Services\Crawling\MsncFetchGate;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MsncDiscover extends Command
{
    protected $signature = 'msnc:discover
                            {--source= : Discover news for a single source id}
                            {--all : Discover news for every enabled msnc news source}
                            {--sync : Run DiscoverNews synchronously instead of queueing}';

    protected $description = 'Discover articles from registered msnc news sources.';

    public function handle(MsncFetchGate $gate): int
    {
        $closedReason = $gate->closedReason();
        if ($closedReason !== null) {
            $this->warn("msnc fetching is closed: {$closedReason}");

            return self::SUCCESS;
        }

        $sources = $this->resolveSources();
        if ($sources === null) {
            $this->error('Specify exactly one of --source=ID or --all.');

            return self::INVALID;
        }

        if ($sources->isEmpty()) {
            $this->info('No matching enabled msnc news source.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        foreach ($sources as $source) {
            if ($sync) {
                $this->info("Discovering source {$source->id} synchronously...");
                DiscoverNews::dispatchSync($source->id);
            } else {
                $this->info("Queueing discover for source {$source->id}.");
                DiscoverNews::dispatch($source->id);
            }
        }

        return self::SUCCESS;
    }

    private function resolveSources(): ?Collection
    {
        $sourceId = $this->option('source');
        $all = (bool) $this->option('all');

        if ($sourceId !== null && $all) {
            return null;
        }
        if ($sourceId === null && ! $all) {
            return null;
        }

        $query = CrawlSource::query()
            ->where('is_enabled', true)
            ->where('crawler_code', 'msnc')
            ->where('source_type', 'news');

        if ($sourceId !== null) {
            return $query->where('id', (int) $sourceId)->get();
        }

        return $query->get();
    }
}
