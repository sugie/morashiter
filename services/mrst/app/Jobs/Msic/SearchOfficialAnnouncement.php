<?php

namespace App\Jobs\Msic;

use App\Models\OfficialAnnouncementSearch;
use App\Services\Crawling\OfficialAnnouncementSearch as Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SearchOfficialAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    public function __construct(public int $searchId)
    {
        $this->onQueue('msnc');
    }

    public function handle(Search $search): void
    {
        $search->run($this->searchId);
    }

    public function failed(?Throwable $exception): void
    {
        OfficialAnnouncementSearch::query()->whereKey($this->searchId)->where('status', 'running')
            ->update(['status' => 'failed', 'error_code' => 'worker_failed', 'completed_at' => now()]);
    }
}
