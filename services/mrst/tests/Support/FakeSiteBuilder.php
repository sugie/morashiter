<?php

namespace Tests\Support;

use App\Exceptions\SiteBuildFailure;
use App\Services\Publishing\SiteBuilder;

/**
 * Stands in for the Node static build: writes a tiny site whose release id is derived from the
 * snapshot, so the same data always gives the same release.
 */
class FakeSiteBuilder implements SiteBuilder
{
    /** @var list<array{snapshot: string, previous: ?array, out: string, indexable: bool}> */
    public array $calls = [];

    public bool $fail = false;

    public function build(string $snapshotPath, ?string $previousReleasePath, string $outDir, bool $indexable): array
    {
        $this->calls[] = [
            'snapshot' => (string) file_get_contents($snapshotPath),
            'previous' => $previousReleasePath === null ? null : json_decode((string) file_get_contents($previousReleasePath), true),
            'out' => $outDir,
            'indexable' => $indexable,
        ];
        if ($this->fail) {
            throw new SiteBuildFailure('build refused: test');
        }

        $snapshot = json_decode((string) file_get_contents($snapshotPath), true);
        $releaseId = substr(hash('sha256', (string) file_get_contents($snapshotPath).($indexable ? 'i' : 'n')), 0, 12);
        $ids = array_column($snapshot['incidents'], 'id');
        @mkdir($outDir.'/assets', 0777, true);
        file_put_contents("{$outDir}/assets/index-{$releaseId}.js", "console.log('{$releaseId}')");
        file_put_contents("{$outDir}/assets/index-shared.css", 'body{}');
        file_put_contents("{$outDir}/favicon.svg", '<svg/>');
        file_put_contents("{$outDir}/robots.txt", "User-agent: *\nDisallow: /\n");
        file_put_contents("{$outDir}/index.html", "<html><body>{$releaseId} ".implode(' ', $ids).'</body></html>');
        $release = ['releaseId' => $releaseId, 'incidentIds' => $ids, 'dataAsOf' => $snapshot['data_as_of'], 'indexable' => $indexable];
        file_put_contents("{$outDir}/release.json", json_encode($release));

        return $release;
    }
}
