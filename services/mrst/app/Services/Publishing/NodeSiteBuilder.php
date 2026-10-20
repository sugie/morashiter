<?php

namespace App\Services\Publishing;

use App\Exceptions\SiteBuildFailure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs the prototype's static build and verification inside the worker image.
 * Node is started through `env -i` so no secret (S3, CDN, DB, APP_KEY) can reach it,
 * and every argument is fixed; snapshot values never appear on the command line.
 */
class NodeSiteBuilder implements SiteBuilder
{
    public const CLEAN_ENV = ['env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'HOME=/tmp', 'NODE_ENV=production'];

    private const TIMEOUT_SECONDS = 300;

    public function build(string $snapshotPath, ?string $previousReleasePath, string $outDir, bool $indexable): array
    {
        $build = [...self::CLEAN_ENV, 'node', 'scripts/build-static.mjs', "--snapshot={$snapshotPath}", "--out={$outDir}"];
        if ($previousReleasePath !== null) {
            $build[] = "--previous-release={$previousReleasePath}";
        }
        if ($indexable) {
            $build[] = '--indexable';
        }
        $result = $this->run($build);
        if (! $result->successful()) {
            throw new SiteBuildFailure('build failed: '.$this->tail($result->errorOutput()));
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
        $release = json_decode((string) end($lines), true);
        if (! is_array($release) || ($release['ok'] ?? false) !== true || ! is_string($release['releaseId'] ?? null)) {
            throw new SiteBuildFailure('build failed: no release summary from the static build');
        }

        $verify = [...self::CLEAN_ENV, 'node', 'scripts/verify-static.mjs', $outDir, "--snapshot={$snapshotPath}"];
        if ($indexable) {
            $verify[] = '--indexable';
        }
        $checked = $this->run($verify);
        if (! $checked->successful()) {
            throw new SiteBuildFailure('verification failed: '.$this->tail($checked->errorOutput().$checked->output()));
        }

        return [
            'releaseId' => $release['releaseId'],
            'incidentIds' => array_values($release['incidentIds'] ?? []),
            'dataAsOf' => $release['dataAsOf'] ?? null,
            'indexable' => (bool) ($release['indexable'] ?? $indexable),
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ProcessResult
    {
        return Process::path((string) config('msgn.site_root', '/opt/site'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->run($command);
    }

    private function tail(string $output): string
    {
        return mb_substr(trim($output), -1000);
    }
}
