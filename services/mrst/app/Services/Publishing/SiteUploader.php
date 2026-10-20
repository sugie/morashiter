<?php

namespace App\Services\Publishing;

use App\Exceptions\PublishFailure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Puts a built site into the private site bucket. Hashed assets go first (and are never
 * re-uploaded or deleted, so pages being viewed keep working), HTML next, release.json last.
 */
class SiteUploader
{
    /**
     * @param  list<string>  $paths  site-relative paths
     * @return list<string>
     */
    public function plannedOrder(array $paths): array
    {
        $rank = fn (string $path): int => match (true) {
            str_starts_with($path, 'assets/') => 0,
            $path === 'index.html' => 3,
            $path === 'release.json' => 4,
            str_ends_with($path, '.html') => 2,
            default => 1,
        };
        usort($paths, fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return $paths;
    }

    /**
     * @return array{ContentType: string, CacheControl: string}
     */
    public function headersFor(string $path): array
    {
        return match (true) {
            $path === 'release.json' => ['ContentType' => 'application/json; charset=utf-8', 'CacheControl' => 'no-store'],
            str_ends_with($path, '.html') => ['ContentType' => 'text/html; charset=utf-8', 'CacheControl' => 'public, max-age=300'],
            str_ends_with($path, '.js') => ['ContentType' => 'text/javascript; charset=utf-8', 'CacheControl' => 'public, max-age=31536000, immutable'],
            str_ends_with($path, '.css') => ['ContentType' => 'text/css; charset=utf-8', 'CacheControl' => 'public, max-age=31536000, immutable'],
            str_ends_with($path, '.svg') => ['ContentType' => 'image/svg+xml', 'CacheControl' => 'public, max-age=86400'],
            $path === 'robots.txt' => ['ContentType' => 'text/plain; charset=utf-8', 'CacheControl' => 'public, max-age=300'],
            str_ends_with($path, '.xml') => ['ContentType' => 'application/xml; charset=utf-8', 'CacheControl' => 'public, max-age=300'],
            default => ['ContentType' => 'application/octet-stream', 'CacheControl' => 'public, max-age=300'],
        };
    }

    /**
     * Upload a site directory; assets already in the bucket are skipped.
     *
     * @return list<string> uploaded paths, in upload order
     *
     * @throws PublishFailure
     */
    public function upload(string $dir): array
    {
        $disk = Storage::disk('site');
        $paths = array_map(
            fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()),
            File::allFiles($dir),
        );

        $uploaded = [];
        try {
            foreach ($this->plannedOrder($paths) as $path) {
                if (str_starts_with($path, 'assets/') && $disk->exists($path)) {
                    continue;
                }
                $disk->put($path, (string) file_get_contents("{$dir}/{$path}"), $this->headersFor($path) + ['visibility' => 'private']);
                $uploaded[] = $path;
            }
        } catch (Throwable $e) {
            throw new PublishFailure('upload failed at '.($path ?? '?').': '.class_basename($e));
        }

        return $uploaded;
    }
}
