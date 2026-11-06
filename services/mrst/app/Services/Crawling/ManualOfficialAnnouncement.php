<?php

namespace App\Services\Crawling;

use App\Exceptions\UrlPolicyViolation;
use App\Jobs\Msic\FetchOfficialSource;
use App\Models\CrawlSource;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualOfficialAnnouncement
{
    public function __construct(private readonly UrlPolicy $policy, private readonly OfficialFetchGate $gate, private readonly OfficialPageLinkFinder $finder) {}

    public function validatedUrl(string $url, OfficialDomain $domain): string
    {
        $parts = @parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw ValidationException::withMessages(['url' => '認証情報を含まない HTTPS の公式発表URLを入力してください（ポートは443のみ）。']);
        }
        try {
            $normalized = $this->policy->validate($url, new CrawlSource(['allowed_hosts' => [$domain->host]]));
        } catch (UrlPolicyViolation) {
            throw ValidationException::withMessages(['url' => '選択した公式ドメインの公開URLを確認してください。接続先の安全性を確認できませんでした。']);
        }
        $normalized = preg_replace('#^(https://[^/:]+):443/#', '$1/', $normalized);
        if ($this->finder->isTopPage($normalized)) {
            throw ValidationException::withMessages(['url' => 'トップページではなく、個別の公式発表ページまたはPDFのURLを入力してください。']);
        }

        return $normalized;
    }

    public function submit(string $url, OfficialDomain $domain, int $adminId, ?int $suggestedBySearchId = null): OfficialLinkCandidate
    {
        $url = $this->validatedUrl($url, $domain);
        $hash = hash('sha256', $url);

        return DB::transaction(function () use ($url, $hash, $domain, $adminId, $suggestedBySearchId): OfficialLinkCandidate {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['manual-announcement:'.$hash]);
            $existing = OfficialLinkCandidate::query()->whereNull('source_document_id')->where('url_hash', $hash)->first();
            if ($existing !== null) {
                return $existing;
            }
            $document = OfficialDocument::query()->where('url_hash', $hash)->latest('id')->first();
            $candidate = OfficialLinkCandidate::query()->create([
                'source_document_id' => null,
                'submitted_by_admin_id' => $adminId,
                'suggested_by_search_id' => $suggestedBySearchId,
                'url' => $url, 'url_hash' => $hash, 'host' => $domain->host,
                'status' => $document === null ? 'accepted' : 'fetched',
                'fetched_at' => $document?->fetched_at,
            ]);
            if ($document === null && $this->gate->closedReason() === null) {
                FetchOfficialSource::dispatch($candidate->id)->afterCommit();
            }

            return $candidate;
        });
    }

    public function retry(OfficialLinkCandidate $candidate, int $adminId): void
    {
        abort_unless($candidate->source_document_id === null, 404);
        $domain = OfficialDomain::query()->where('host', $candidate->host)->first();
        if ($domain === null) {
            throw ValidationException::withMessages(['url' => '公式ドメインが未登録です。登録を確認してください。']);
        }
        $this->validatedUrl($candidate->url, $domain);
        DB::transaction(function () use ($candidate, $adminId): void {
            $row = OfficialLinkCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->source_document_id === null, 404);
            abort_unless($row->status === 'failed' || ($row->status === 'fetched' && $row->error_code === 'no_incident_link'), 409, '取得失敗の候補だけ再試行できます。画面を更新してください。');
            $row->update([
                'status' => 'accepted', 'error_code' => null, 'fetched_at' => null,
                'last_retried_by_admin_id' => $adminId, 'last_retried_at' => now(),
            ]);
            if ($this->gate->closedReason() === null) {
                FetchOfficialSource::dispatch($row->id)->afterCommit();
            }
        });
    }
}
