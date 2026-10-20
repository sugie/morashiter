<?php

namespace App\Services\Publishing;

use App\Exceptions\PublishFailure;
use App\Exceptions\SiteBuildFailure;
use App\Models\SiteRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * msgn: approved revisions -> snapshot -> static build + verification -> private archive ->
 * upload (assets, HTML, release.json last) -> CDN purge -> read-back confirmation -> live.
 * If anything after the build fails, the previous live release is uploaded again and purged.
 */
class SitePublisher
{
    public const LOCK = 'msgn:publish';

    public function __construct(
        private readonly PublicSnapshotBuilder $snapshots,
        private readonly SiteBuilder $builder,
        private readonly SiteUploader $uploader,
        private readonly CdnPurger $purger,
        private readonly ReleaseConfirmer $confirmer,
    ) {}

    public function publish(?int $adminId): ?SiteRelease
    {
        if (! config('msgn.enabled', false)) {
            return null;
        }

        $lock = Cache::lock(self::LOCK, 1800);
        if (! $lock->get()) {
            return new SiteRelease(['status' => 'busy']);
        }

        try {
            return $this->run($adminId);
        } finally {
            $lock->release();
        }
    }

    private function run(?int $adminId): SiteRelease
    {
        $json = $this->snapshots->json();
        $sha = hash('sha256', $json);
        $indexable = (bool) config('msgn.indexable', false);
        $live = SiteRelease::query()->where('status', 'live')->latest('id')->first();

        $release = SiteRelease::query()->create([
            'status' => 'building', 'snapshot_sha256' => $sha, 'indexable' => $indexable,
            'triggered_by_admin_id' => $adminId, 'started_at' => now(),
        ]);
        if ($live !== null && $live->snapshot_sha256 === $sha && $live->indexable === $indexable) {
            $release->update(['status' => 'skipped', 'release_id' => $live->release_id, 'incident_ids' => $live->incident_ids, 'data_as_of' => $live->data_as_of, 'finished_at' => now()]);

            return $release;
        }

        $local = Storage::disk('local');
        $work = "msgn/work/{$release->id}";
        $local->put("{$work}/snapshot.json", $json);
        $previousPath = null;
        if ($live !== null) {
            $local->put("{$work}/previous-release.json", json_encode(['releaseId' => $live->release_id, 'incidentIds' => $live->incident_ids], JSON_THROW_ON_ERROR));
            $previousPath = $local->path("{$work}/previous-release.json");
        }
        $dist = $local->path("{$work}/dist");

        try {
            try {
                $built = $this->builder->build($local->path("{$work}/snapshot.json"), $previousPath, $dist, $indexable);
            } catch (SiteBuildFailure $e) {
                return $this->fail($release, $e->getMessage());
            }
            $release->update(['release_id' => $built['releaseId'], 'incident_ids' => $built['incidentIds'], 'data_as_of' => $built['dataAsOf'], 'status' => 'built']);
            File::copyDirectory($dist, $local->path($release->archivePath()));

            try {
                $this->uploader->upload($dist);
                $release->update(['status' => 'uploaded']);
                $this->purger->purge();
                if (! $this->confirmer->confirm($built['releaseId'])) {
                    throw new PublishFailure('release not confirmed: the public release.json did not show '.$built['releaseId']);
                }
            } catch (PublishFailure $e) {
                $this->restore($live);

                return $this->fail($release, $e->getMessage());
            }

            DB::transaction(function () use ($release, $live): void {
                $live?->update(['status' => 'superseded']);
                $release->update(['status' => 'live', 'finished_at' => now(), 'error' => null]);
            });

            return $release;
        } finally {
            $local->deleteDirectory($work);
        }
    }

    private function restore(?SiteRelease $live): void
    {
        if ($live === null) {
            return;
        }
        try {
            $this->uploader->upload(Storage::disk('local')->path($live->archivePath()));
            $this->purger->purge();
        } catch (Throwable $e) {
            Log::error('msgn: restoring the previous live release failed.', ['release_id' => $live->release_id, 'reason' => $e->getMessage()]);
        }
    }

    private function fail(SiteRelease $release, string $error): SiteRelease
    {
        $release->update(['status' => 'failed', 'error' => mb_substr($error, 0, 2000), 'finished_at' => now()]);
        Log::warning('msgn: publish failed.', ['site_release' => $release->id, 'reason' => $release->error]);

        return $release;
    }
}
