<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrawlRun;
use App\Models\IncidentReview;
use App\Models\OfficialDocument;
use App\Models\OfficialLinkCandidate;
use App\Models\SiteRelease;
use App\Services\Publishing\PublicSnapshotBuilder;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(PublicSnapshotBuilder $snapshots): View
    {
        $links = OfficialLinkCandidate::query()->toBase()
            ->selectRaw("count(distinct case when status = 'pending' then host end) as pending_hosts")
            ->selectRaw("count(case when status = 'pending' then 1 end) as pending_links")
            ->selectRaw("count(case when status = 'accepted' then 1 end) as fetch_waiting")
            ->selectRaw("count(case when status = 'running' then 1 end) as fetch_running")
            ->selectRaw("count(case when status = 'failed' then 1 end) as fetch_failed")
            ->selectRaw("count(case when status = 'fetched' and error_code = 'no_incident_link' then 1 end) as missing_links")
            ->first();
        $reviews = IncidentReview::query()->toBase()
            ->selectRaw("count(case when status = 'pending_review' then 1 end) as review_ready")
            ->selectRaw("count(case when status = 'analysis_failed' then 1 end) as analysis_failed")
            ->first();
        $live = SiteRelease::query()->where('status', 'live')->latest('id')->first();
        $json = $snapshots->json();
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $unpublished = array_diff(array_column($snapshot['incidents'], 'id'), $live?->incident_ids ?? []);

        return view('admin.dashboard', [
            'counts' => [
                'pending_hosts' => (int) $links->pending_hosts,
                'pending_links' => (int) $links->pending_links,
                'fetch_waiting' => (int) $links->fetch_waiting,
                'fetch_running' => (int) $links->fetch_running,
                'fetch_failed' => (int) $links->fetch_failed,
                'missing_links' => (int) $links->missing_links,
                'analysis_waiting' => OfficialDocument::query()->whereDoesntHave('review')->count(),
                'review_queue' => (int) $reviews->review_ready + (int) $reviews->analysis_failed,
                'review_ready' => (int) $reviews->review_ready,
                'analysis_failed' => (int) $reviews->analysis_failed,
                'publish_new' => count($unpublished),
            ],
            'hasUnpublishedChanges' => $live !== null
                ? $live->snapshot_sha256 !== hash('sha256', $json)
                : $snapshot['incidents'] !== [] || $snapshot['withdrawals'] !== [],
            'latestRelease' => SiteRelease::query()->latest('id')->first(),
            'liveRelease' => $live,
            'latestCrawl' => CrawlRun::query()->with('source:id,name')->latest('id')->first(),
            'publishingEnabled' => (bool) config('msgn.enabled', false),
        ]);
    }
}
