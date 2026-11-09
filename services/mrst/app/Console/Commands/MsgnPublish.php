<?php

namespace App\Console\Commands;

use App\Services\Publishing\SitePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MsgnPublish extends Command
{
    protected $signature = 'msgn:publish';

    protected $description = 'Publish approved incidents to the public site now (same steps as the admin publish button).';

    public function handle(SitePublisher $publisher): int
    {
        $this->line(now()->toIso8601String().' msgn:publish started');
        $release = $publisher->publish(null);
        if ($release === null) {
            $this->warn('Publishing is disabled (MSGN_ENABLED=false).');

            return self::SUCCESS;
        }

        $this->line(now()->toIso8601String()." status={$release->status} release={$release->release_id} incidents=".count($release->incident_ids ?? []));
        Log::info('msgn: publish completed.', ['status' => $release->status, 'site_release' => $release->id, 'release_id' => $release->release_id]);
        if ($release->error) {
            $this->error($release->error);
        }

        return in_array($release->status, ['live', 'skipped'], true) ? self::SUCCESS : self::FAILURE;
    }
}
