<?php

namespace App\Console\Commands;

use App\Services\Publishing\SitePublisher;
use Illuminate\Console\Command;

class MsgnPublish extends Command
{
    protected $signature = 'msgn:publish';

    protected $description = 'Publish approved incidents to the public site now (same steps as the admin publish button).';

    public function handle(SitePublisher $publisher): int
    {
        $release = $publisher->publish(null);
        if ($release === null) {
            $this->warn('Publishing is disabled (MSGN_ENABLED=false).');

            return self::SUCCESS;
        }

        $this->line("status={$release->status} release={$release->release_id} incidents=".count($release->incident_ids ?? []));
        if ($release->error) {
            $this->error($release->error);
        }

        return in_array($release->status, ['live', 'skipped'], true) ? self::SUCCESS : self::FAILURE;
    }
}
