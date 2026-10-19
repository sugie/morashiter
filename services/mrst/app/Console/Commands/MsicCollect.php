<?php

namespace App\Console\Commands;

use App\Jobs\Msic\FetchOfficialSource;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\OfficialFetchGate;
use Illuminate\Console\Command;

class MsicCollect extends Command
{
    protected $signature = 'msic:collect';

    protected $description = 'Queue fetches of companies\' own announcements for accepted link candidates.';

    public function handle(OfficialFetchGate $gate): int
    {
        $closedReason = $gate->closedReason();
        if ($closedReason !== null) {
            $this->warn("msic fetching is closed: {$closedReason}");

            return self::SUCCESS;
        }

        $candidateIds = OfficialLinkCandidate::query()
            ->where('status', 'accepted')
            ->orderBy('id')
            ->limit(max(1, (int) config('crawler.official_batch_size', 20)))
            ->pluck('id');

        foreach ($candidateIds as $candidateId) {
            FetchOfficialSource::dispatch($candidateId);
        }
        $this->info("Queued {$candidateIds->count()} official source fetches.");

        return self::SUCCESS;
    }
}
