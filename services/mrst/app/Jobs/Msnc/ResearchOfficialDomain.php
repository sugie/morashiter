<?php

namespace App\Jobs\Msnc;

use App\Models\OfficialDomainInvestigation;
use App\Services\Crawling\OfficialDomainResearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ResearchOfficialDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public int $investigationId)
    {
        $this->onQueue('msnc');
    }

    public function handle(OfficialDomainResearch $research): void
    {
        $research->run($this->investigationId);
    }

    public function failed(?Throwable $exception): void
    {
        OfficialDomainInvestigation::query()->whereKey($this->investigationId)->where('status', 'running')
            ->update(['status' => 'failed', 'recommendation' => 'hold', 'error_code' => 'worker_failed', 'reason' => '調査処理が停止しました。人による確認が必要です。']);
    }
}
