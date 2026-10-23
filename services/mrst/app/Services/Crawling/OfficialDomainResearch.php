<?php

namespace App\Services\Crawling;

use App\Exceptions\AiAnalysisFailure;
use App\Jobs\Msnc\ResearchOfficialDomain;
use App\Models\Company;
use App\Models\OfficialDomain;
use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use App\Services\Ai\OfficialDomainResearchResult;
use App\Services\Ai\SakuraDomainResearcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfficialDomainResearch
{
    public function __construct(private readonly OfficialDomainPageCollector $collector, private readonly SakuraDomainResearcher $analyzer) {}

    /** PostgreSQL transaction lock also covers hosts with no investigation/domain row yet. */
    public static function lockHost(string $host): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['domain-research:'.$host]);
    }

    public function request(string $host, bool $again = false): ?OfficialDomainInvestigation
    {
        if (! config('domain_research.enabled')) {
            return null;
        }
        $host = strtolower(trim($host));
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return null;
        }

        return DB::transaction(function () use ($host, $again): ?OfficialDomainInvestigation {
            self::lockHost($host);
            if (OfficialDomain::query()->where('host', $host)->exists()
                || ! OfficialLinkCandidate::query()->where('host', $host)->where('status', 'pending')->exists()) {
                return null;
            }
            $last = OfficialDomainInvestigation::query()->where('host', $host)->latest('generation')->first();
            if ($last !== null && (! $again || in_array($last->status, ['queued', 'running'], true))) {
                return $last;
            }
            $item = OfficialDomainInvestigation::query()->create([
                'host' => $host, 'generation' => ($last?->generation ?? 0) + 1,
                'status' => 'queued', 'recommendation' => 'hold',
                'rule_version' => config('domain_research.rule_version'),
                'prompt_version' => config('domain_research.prompt_version'),
            ]);
            ResearchOfficialDomain::dispatch($item->id)->afterCommit();

            return $item;
        });
    }

    public function run(int $id): void
    {
        if (! config('domain_research.enabled')) {
            return;
        }
        $item = DB::transaction(function () use ($id): ?OfficialDomainInvestigation {
            $row = OfficialDomainInvestigation::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'queued') {
                return null;
            }
            if (DB::table('official_domain_decisions')->where('investigation_id', $row->id)->exists()
                || OfficialDomain::query()->where('host', $row->host)->exists()
                || ! OfficialLinkCandidate::query()->where('host', $row->host)->where('status', 'pending')->exists()) {
                $row->update(['status' => 'superseded', 'completed_at' => now()]);

                return null;
            }
            if ($row->attempts >= max(1, (int) config('domain_research.max_attempts', 2))) {
                $row->update(['status' => 'failed', 'error_code' => 'attempt_limit']);

                return null;
            }
            $row->update(['status' => 'running', 'started_at' => now()]);

            return $row;
        });
        if ($item === null) {
            return;
        }

        try {
            $snapshot = $item->snapshot_path;
            $collected = $snapshot !== null && Storage::disk('local')->exists($snapshot)
                ? json_decode(Storage::disk('local')->get($snapshot), true, flags: JSON_THROW_ON_ERROR)
                : $this->collector->collect($item->host);
            $snapshot ??= 'domain-research/'.$item->id.'/snapshot.json';
            if (! Storage::disk('local')->put($snapshot, json_encode($collected, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('research_snapshot_failed');
            }
            $item->update([
                'snapshot_path' => $snapshot,
                'pages' => array_map(fn (array $page): array => array_diff_key($page, ['text' => true]), $collected['pages']),
                'final_url' => $collected['final_url'], 'redirect_url' => $collected['redirect_url'],
            ]);
            if (($collected['error'] ?? null) === 'disabled') {
                $item->update(['status' => 'queued', 'snapshot_path' => null]);

                return;
            }
            if (($collected['error'] ?? null) !== null) {
                $this->finish($item, ['recommendation' => 'hold', 'error_code' => $collected['error'], 'reason' => 'ページ取得を完了できません。根拠を人が確認してください。']);

                return;
            }
            $pages = $collected['pages'];
            if (! array_filter($pages, fn (array $page): bool => $page['is_overview'] && trim($page['text']) !== '')) {
                $this->finish($item, ['recommendation' => 'hold', 'error_code' => 'no_overview', 'reason' => '会社概要・組織概要を確認できません。']);

                return;
            }
            if (! config('domain_research.enabled')) {
                $item->update(['status' => 'queued']);

                return;
            }
            if (config('ai.provider') !== 'sakura') {
                $this->finish($item, ['recommendation' => 'hold', 'error_code' => 'provider_disabled', 'reason' => '実AIが有効ではありません。']);

                return;
            }
            $this->analyzer->assertConfigured();
            if (! $this->reserveBudget($item)) {
                $item->update(['status' => 'queued', 'error_code' => 'daily_limit', 'reason' => '日次上限に達しました。次の枠で再開します。']);

                return;
            }
            $raw = $this->analyzer->analyze($pages);
            $result = OfficialDomainResearchResult::validate($raw, $pages);
            if ($result['organization_name'] !== null && Company::query()->where('name', $result['organization_name'])->count() > 1) {
                $result['recommendation'] = 'hold';
                $result['validation_reason'] = '同名の企業が複数あります。登録先を人が確認してください。';
            }
            $this->finish($item, [
                'organization_name' => $result['organization_name'], 'recommendation' => $result['recommendation'],
                'evidence' => $result['evidence'], 'raw_proposal' => $raw,
                'reason' => $result['validation_reason'] ?? $result['reason'],
                'error_code' => $result['validation_reason'] !== null ? 'evidence_unconfirmed' : null,
            ]);
        } catch (AiAnalysisFailure $e) {
            $item->refresh();
            if ($e->retryable && $item->attempts < max(1, (int) config('domain_research.max_attempts', 2))) {
                $item->update(['status' => 'queued', 'error_code' => 'ai_retry', 'reason' => 'AI接続に失敗しました。上限内で再試行します。']);
            } else {
                $this->finish($item, ['error_code' => 'ai_failed', 'recommendation' => 'hold', 'reason' => 'AI調査に失敗しました。人による確認が必要です。'], 'failed');
            }
        } catch (Throwable $e) {
            $this->finish($item, ['error_code' => 'research_failed', 'recommendation' => 'hold', 'reason' => '調査を完了できませんでした。再調査または手動確認を行ってください。'], 'failed');
            // Do not log response bodies or provider credentials.
            report(new \RuntimeException('Domain research '.$item->id.' failed: '.class_basename($e)));
        }
    }

    private function reserveBudget(OfficialDomainInvestigation $item): bool
    {
        return DB::transaction(function () use ($item): bool {
            $day = now(config('domain_research.budget_timezone'))->toDateString();
            DB::table('domain_research_budgets')->insertOrIgnore(['day' => $day, 'used' => 0]);
            $budget = DB::table('domain_research_budgets')->where('day', $day)->lockForUpdate()->first();
            if ($budget->used >= max(0, (int) config('domain_research.daily_limit', 10))) {
                return false;
            }
            DB::table('domain_research_budgets')->where('day', $day)->increment('used');
            $item->increment('attempts');
            $item->update(['provider' => 'sakura', 'model' => config('ai.sakura.model')]);

            return true;
        });
    }

    private function finish(OfficialDomainInvestigation $item, array $values, string $status = 'completed'): void
    {
        DB::transaction(function () use ($item, $values, $status): void {
            self::lockHost($item->host);
            $row = OfficialDomainInvestigation::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $latest = OfficialDomainInvestigation::query()->where('host', $item->host)->max('generation');
            if ($row->status !== 'running') {
                return;
            }
            if ($row->generation !== $latest || OfficialDomain::query()->where('host', $item->host)->exists()
                || ! OfficialLinkCandidate::query()->where('host', $item->host)->where('status', 'pending')->exists()
                || DB::table('official_domain_decisions')->where('investigation_id', $row->id)->exists()) {
                $row->update(['status' => 'superseded', 'completed_at' => now()]);

                return;
            }
            $row->update($values + ['status' => $status, 'completed_at' => now()]);
        });
    }

    public function decide(array $data, int $adminId): void
    {
        DB::transaction(function () use ($data, $adminId): void {
            self::lockHost($data['host']);
            $row = OfficialDomainInvestigation::query()->whereKey($data['investigation_id'])->lockForUpdate()->first();
            abort_unless($row !== null && $row->host === $data['host']
                && $row->generation === OfficialDomainInvestigation::query()->where('host', $row->host)->max('generation')
                && ! OfficialDomain::query()->where('host', $row->host)->exists()
                && OfficialLinkCandidate::query()->where('host', $row->host)->where('status', 'pending')->exists(), 409, '調査結果が更新されています。再読み込みしてください。');
            $company = null;
            $name = $data['company_name'] ?? null;
            if ($data['decision'] === 'register') {
                abort_unless($row->status === 'completed' && $row->recommendation === 'recommend' && ! empty($row->evidence), 409, '根拠を確認できません。再調査または保留してください。');
                if (! empty($data['company_id'])) {
                    $company = Company::query()->whereKey($data['company_id'])->lockForUpdate()->firstOrFail();
                    if ($company->name !== $name) {
                        throw ValidationException::withMessages(['company_name' => '選択した企業の登録名と一致させてください。']);
                    }
                } else {
                    $existing = Company::query()->where('name', $name)->get();
                    if ($existing->count() > 1) {
                        throw ValidationException::withMessages(['company_id' => '同名の企業が複数あります。登録先を選択してください。']);
                    }
                    $company = $existing->first() ?? Company::query()->create(['name' => $name]);
                }
                OfficialDomain::query()->create([
                    'company_id' => $company->id, 'host' => $row->host, 'verified_by_admin_id' => $adminId,
                    'verified_at' => now(), 'note' => $data['reason'],
                ]);
                OfficialLinkCandidate::query()->where('host', $row->host)->where('status', 'pending')->update(['status' => 'accepted', 'updated_at' => now()]);
            }
            DB::table('official_domain_decisions')->insert([
                'investigation_id' => $row->id, 'host' => $row->host, 'decision' => $data['decision'],
                'company_name' => $name, 'company_id' => $company?->id, 'admin_id' => $adminId,
                'reason' => $data['reason'], 'decided_at' => now(),
                'confirmations' => json_encode(array_intersect_key($data, array_flip(['evidence_checked', 'ownership_checked', 'name_checked', 'batch_checked'])), JSON_THROW_ON_ERROR),
            ]);
            if ($row->status === 'queued') {
                $row->update(['status' => 'superseded', 'completed_at' => now()]);
            }
        });
    }

    public function dispatchPending(): void
    {
        if (! config('domain_research.enabled')) {
            return;
        }
        OfficialDomainInvestigation::query()->where('status', 'running')->where('started_at', '<', now()->subMinutes(5))
            ->update(['status' => 'failed', 'recommendation' => 'hold', 'error_code' => 'worker_interrupted', 'reason' => '調査処理が中断されました。再調査してください。']);
        OfficialDomainInvestigation::query()->where('status', 'queued')->orderBy('id')->limit(50)->get()
            ->each(fn (OfficialDomainInvestigation $item) => ResearchOfficialDomain::dispatch($item->id));
    }
}
