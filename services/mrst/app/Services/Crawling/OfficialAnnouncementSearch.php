<?php

namespace App\Services\Crawling;

use App\Exceptions\AiAnalysisFailure;
use App\Jobs\Msic\SearchOfficialAnnouncement;
use App\Models\OfficialAnnouncementSearch as SearchRow;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Ai\AnnouncementSearchResult;
use App\Services\Ai\SakuraAnnouncementMatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Looks for the individual official announcement behind a candidate whose top page had no
 * incident link. Rules collect same-host candidates; AI (optional) picks and checks them.
 * Nothing is added automatically: an admin adds a suggested URL through the manual form.
 */
class OfficialAnnouncementSearch
{
    public function __construct(
        private readonly AnnouncementLinkCollector $collector,
        private readonly SakuraAnnouncementMatcher $matcher,
        private readonly OfficialFetchGate $gate,
    ) {}

    public function isEligible(OfficialLinkCandidate $candidate): bool
    {
        return $candidate->status === 'fetched' && $candidate->error_code === 'no_incident_link'
            && OfficialDomain::query()->where('host', $candidate->host)->exists();
    }

    public function request(OfficialLinkCandidate $candidate, ?int $adminId = null): ?SearchRow
    {
        if (! config('announcement_search.enabled')) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $adminId): ?SearchRow {
            $row = OfficialLinkCandidate::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if ($row === null || ! $this->isEligible($row)) {
                return null;
            }
            $active = SearchRow::query()->where('official_link_candidate_id', $row->id)->whereIn('status', ['queued', 'running'])->first();
            if ($active !== null) {
                return $active;
            }
            $search = SearchRow::query()->create([
                'official_link_candidate_id' => $row->id, 'status' => 'queued', 'requested_by_admin_id' => $adminId,
            ]);
            SearchOfficialAnnouncement::dispatch($search->id)->afterCommit();

            return $search;
        });
    }

    public function run(int $id): void
    {
        if (! config('announcement_search.enabled') || $this->gate->closedReason() !== null) {
            return;
        }
        $useAi = (bool) config('announcement_search.ai', true) && config('ai.provider') === 'sakura';
        $search = DB::transaction(function () use ($id, $useAi): ?SearchRow {
            $row = SearchRow::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'queued') {
                return null;
            }
            $candidate = OfficialLinkCandidate::query()->find($row->official_link_candidate_id);
            if ($candidate === null || ! $this->isEligible($candidate)) {
                $row->update(['status' => 'superseded', 'completed_at' => now()]);

                return null;
            }
            // Spend the AI budget before fetching, so a full day does not re-crawl every hour.
            if ($useAi && ! $this->reserveBudget()) {
                $row->update(['error_code' => 'daily_limit']);

                return null;
            }
            $row->update(['status' => 'running', 'started_at' => now(), 'error_code' => null]);

            return $row;
        });
        if ($search === null) {
            return;
        }

        try {
            $candidate = OfficialLinkCandidate::query()->with('sourceDocument')->findOrFail($search->official_link_candidate_id);
            $domain = OfficialDomain::query()->with('company')->where('host', $candidate->host)->firstOrFail();
            $news = [
                'title' => $candidate->sourceDocument?->title,
                'published_on' => $candidate->sourceDocument?->published_on?->toDateString(),
                'organization' => $domain->company?->name,
            ];
            $collected = $this->collector->collect($domain->host, $news['title'], $news['published_on']);
            $values = [
                'rule_candidates' => $collected['candidates'], 'pages_fetched' => $collected['pages_fetched'],
                'proposals' => [], 'analyzer' => 'rules', 'error_code' => $collected['error'],
            ];
            if ($collected['candidates'] === []) {
                $values['error_code'] ??= 'no_candidates';
                $this->finish($search, $values);

                return;
            }
            if ($useAi) {
                $values = $this->matchWithAi($news, $domain->host, $collected) + $values;
            }
            $this->finish($search, $values);
        } catch (Throwable $e) {
            $this->finish($search, ['error_code' => 'search_failed'], 'failed');
            // Do not log page bodies, AI responses or credentials.
            report(new \RuntimeException('Announcement search '.$search->id.' failed: '.class_basename($e)));
        }
    }

    /**
     * @param  array{title: ?string, published_on: ?string, organization: ?string}  $news
     * @param  array{candidates: list<array{url: string, title: ?string, published_on: ?string, score: int}>, disallow: list<string>}  $collected
     * @return array<string, mixed>
     */
    private function matchWithAi(array $news, string $host, array $collected): array
    {
        $candidates = $collected['candidates'];
        try {
            $this->matcher->assertConfigured();
            $positions = AnnouncementSearchResult::selected(
                $this->matcher->rank($news, $candidates), count($candidates), max(1, (int) config('announcement_search.max_verified', 3)),
            );
            $proposals = [];
            foreach ($positions as $rank => $position) {
                $candidate = $candidates[$position];
                $page = $this->collector->fetchText($host, $candidate['url'], $collected['disallow']);
                if ($page === null) {
                    continue;
                }
                $checked = AnnouncementSearchResult::verdict($this->matcher->verify($news, $candidate['url'], $page['text']), $page['text']);
                if ($checked['verdict'] === 'different') {
                    continue;
                }
                $proposals[] = [
                    'url' => $candidate['url'], 'title' => $candidate['title'], 'published_on' => $candidate['published_on'],
                    'verdict' => $checked['verdict'], 'quotes' => $checked['quotes'], 'rank' => $rank + 1,
                ];
            }
            usort($proposals, fn (array $a, array $b): int => [$a['verdict'] !== 'same', $a['rank']] <=> [$b['verdict'] !== 'same', $b['rank']]);

            return ['proposals' => $proposals, 'analyzer' => 'sakura:'.config('ai.sakura.model')];
        } catch (AiAnalysisFailure) {
            return ['proposals' => [], 'analyzer' => 'rules', 'error_code' => 'ai_failed'];
        }
    }

    public function dismiss(SearchRow $search, int $adminId, string $reason): void
    {
        DB::transaction(function () use ($search, $adminId, $reason): void {
            $row = SearchRow::query()->whereKey($search->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'completed', 409, '完了した探索だけを閉じられます。画面を更新してください。');
            $row->update(['status' => 'dismissed', 'dismissed_by_admin_id' => $adminId, 'dismiss_reason' => $reason]);
        });
    }

    /**
     * Scheduler entry: recover stuck searches, queue new ones (auto) and dispatch queued ones.
     */
    public function dispatchPending(): void
    {
        if (! config('announcement_search.enabled')) {
            return;
        }
        SearchRow::query()->where('status', 'running')->where('started_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error_code' => 'worker_interrupted', 'completed_at' => now()]);
        if (config('announcement_search.auto', true)) {
            OfficialLinkCandidate::query()
                ->where('status', 'fetched')->where('error_code', 'no_incident_link')
                ->whereIn('host', OfficialDomain::query()->select('host'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('official_announcement_searches')
                    ->whereColumn('official_announcement_searches.official_link_candidate_id', 'official_link_candidates.id'))
                ->orderBy('id')->limit(20)->get()
                ->each(function (OfficialLinkCandidate $candidate): void {
                    try {
                        SearchRow::query()->create(['official_link_candidate_id' => $candidate->id, 'status' => 'queued']);
                    } catch (QueryException) {
                        // Another tick or an admin queued it first (partial unique index).
                    }
                });
        }
        SearchRow::query()->where('status', 'queued')->orderBy('id')->limit(20)->pluck('id')
            ->each(fn (int $id) => SearchOfficialAnnouncement::dispatch($id));
    }

    private function reserveBudget(): bool
    {
        $day = now((string) config('announcement_search.budget_timezone', 'Asia/Tokyo'))->toDateString();
        DB::table('announcement_search_budgets')->insertOrIgnore(['day' => $day, 'used' => 0]);
        $budget = DB::table('announcement_search_budgets')->where('day', $day)->lockForUpdate()->first();
        if ($budget->used >= max(0, (int) config('announcement_search.daily_limit', 20))) {
            return false;
        }
        DB::table('announcement_search_budgets')->where('day', $day)->increment('used');

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function finish(SearchRow $search, array $values, string $status = 'completed'): void
    {
        DB::transaction(function () use ($search, $values, $status): void {
            $row = SearchRow::query()->whereKey($search->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'running') {
                return;
            }
            $row->update($values + ['status' => $status, 'completed_at' => now()]);
        });
    }
}
