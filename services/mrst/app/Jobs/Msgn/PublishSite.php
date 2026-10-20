<?php

namespace App\Jobs\Msgn;

use App\Services\Publishing\SitePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs on the worker (the image with Node and the static builder).
 */
class PublishSite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(public ?int $adminId)
    {
        $this->onQueue('msnc');
    }

    public function handle(SitePublisher $publisher): void
    {
        $publisher->publish($this->adminId);
    }
}
