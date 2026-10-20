<?php

namespace App\Services\Publishing;

use App\Exceptions\SiteBuildFailure;

interface SiteBuilder
{
    /**
     * Build and verify the static site from a public snapshot into $outDir.
     *
     * @return array{releaseId: string, incidentIds: list<string>, dataAsOf: ?string, indexable: bool}
     *
     * @throws SiteBuildFailure
     */
    public function build(string $snapshotPath, ?string $previousReleasePath, string $outDir, bool $indexable): array;
}
