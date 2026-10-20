<?php

namespace App\Console\Commands;

use App\Services\Publishing\PublicSnapshotBuilder;
use Illuminate\Console\Command;

class MsgnSnapshot extends Command
{
    protected $signature = 'msgn:snapshot {--output= : File to write the public snapshot JSON to}';

    protected $description = 'Write the public snapshot (approved revisions and withdrawal notices) for the static build.';

    public function handle(PublicSnapshotBuilder $builder): int
    {
        $output = (string) $this->option('output');
        if ($output === '') {
            $this->error('--output is required.');

            return self::INVALID;
        }

        $json = $builder->json();
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0750, true);
        }
        file_put_contents($output, $json);
        $this->info('snapshot sha256 '.hash('sha256', $json).' written to '.$output);

        return self::SUCCESS;
    }
}
