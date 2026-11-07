<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OfficialAnnouncementSearch;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\ManualOfficialAnnouncement;
use App\Services\Crawling\OfficialFetchGate;
use App\Services\Crawling\RegistrableDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ManualOfficialAnnouncementController extends Controller
{
    public function index(OfficialFetchGate $gate): View
    {
        $submissions = OfficialLinkCandidate::query()->whereNull('source_document_id')->latest('id')->paginate(25);
        $unresolved = OfficialLinkCandidate::query()->with('sourceDocument')->where('error_code', 'no_incident_link')->orderBy('id')->paginate(25, ['*'], 'unresolved_page');
        $domains = OfficialDomain::query()->with('company')->orderBy('host')->get();

        return view('admin.link-candidates.manual', [
            'submissions' => $submissions,
            'documents' => OfficialDocument::query()->with('review')->whereIn('url_hash', $submissions->pluck('url_hash'))->latest('id')->get()->groupBy('url_hash'),
            'domains' => $domains,
            'domainsByHost' => $domains->keyBy('host'),
            'unresolved' => $unresolved,
            // Latest search per unresolved candidate.
            'searches' => OfficialAnnouncementSearch::query()->whereIn('official_link_candidate_id', $unresolved->pluck('id'))
                ->latest('id')->get()->unique('official_link_candidate_id')->keyBy('official_link_candidate_id'),
            'searchEnabled' => (bool) config('announcement_search.enabled'),
            'companyNames' => Company::query()->orderBy('name')->pluck('name')->unique()->values()->all(),
            'fetchClosed' => $gate->closedReason(),
            'aiProvider' => config('ai.provider', 'mock'),
        ]);
    }

    public function store(Request $request, ManualOfficialAnnouncement $submissions): RedirectResponse
    {
        $data = $request->validate([
            'official_domain_id' => ['required', 'integer', 'exists:official_domains,id'],
            'url' => ['required', 'string', 'max:2048'],
            'source_checked' => ['accepted'],
            'suggested_by_search_id' => ['nullable', 'integer', 'exists:official_announcement_searches,id'],
            'register_related_host' => ['nullable', 'boolean'],
        ]);
        $domain = OfficialDomain::query()->findOrFail($data['official_domain_id']);
        $relatedHost = $this->relatedHost($data['url'], $domain, (bool) ($data['register_related_host'] ?? false), $submissions);
        if ($relatedHost !== null) {
            // Register the sibling host and add the URL together, so a refused URL registers nothing.
            $candidate = DB::transaction(function () use ($relatedHost, $domain, $data, $submissions, $request): OfficialLinkCandidate {
                $related = OfficialDomain::query()->create([
                    'company_id' => $domain->company_id, 'host' => $relatedHost,
                    'verified_by_admin_id' => $request->user('admin')->id, 'verified_at' => now(),
                    'note' => '関連ホストとして追加（元：'.$domain->host.'）',
                ]);

                return $submissions->submit($data['url'], $related, $request->user('admin')->id);
            });

            return redirect()->route('admin.official-announcements.index')
                ->with('status', $relatedHost.' を同じ組織の公式ホストとして登録し、公式発表URLを追加しました。');
        }
        $domain = OfficialDomain::query()->where('host', strtolower((string) parse_url($data['url'], PHP_URL_HOST)))
            ->where('company_id', $domain->company_id)->first() ?? $domain;
        $searchId = isset($data['suggested_by_search_id']) ? (int) $data['suggested_by_search_id'] : null;
        if ($searchId !== null) {
            // Only a URL that search actually suggested may be credited to it.
            $suggested = OfficialAnnouncementSearch::query()->findOrFail($searchId)->suggestedUrls();
            if (! in_array($submissions->validatedUrl($data['url'], $domain), $suggested, true)) {
                throw ValidationException::withMessages(['url' => 'この探索結果に含まれるURLを選んでください。']);
            }
        }
        $candidate = $submissions->submit($data['url'], $domain, $request->user('admin')->id, $searchId);
        $message = $candidate->wasRecentlyCreated
            ? '公式発表URLを追加しました。取得・解析の進捗は下の一覧で確認できます。'
            : 'このURLは追加済みです。下の一覧で進捗を確認してください。取得失敗の場合は「再取得」を使えます。';

        return redirect()->route('admin.official-announcements.index')->with('status', $message);
    }

    /**
     * The URL's host when it is an unregistered sibling (same registrable domain) of the selected
     * official host and the admin confirmed it; null when the URL is on an already usable host.
     */
    private function relatedHost(string $url, OfficialDomain $domain, bool $confirmed, ManualOfficialAnnouncement $submissions): ?string
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if ($host === '' || $host === $domain->host) {
            return null;
        }
        $registered = OfficialDomain::query()->where('host', $host)->first();
        if ($registered !== null) {
            if ($registered->company_id !== $domain->company_id) {
                throw ValidationException::withMessages(['url' => 'このURLのホストは別の組織の公式ドメインとして登録されています。']);
            }

            return null;
        }
        if (! RegistrableDomain::same($host, $domain->host)) {
            throw ValidationException::withMessages(['url' => 'URLのホストが、選んだ組織の公式ドメインと同じドメインではありません。先に「選択肢に公式ドメインがない場合」から登録してください。']);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['register_related_host' => $host.' は未登録です。同じ組織のサイトであることを確かめて、関連ホストとして登録するチェックを入れてください。']);
        }
        // Same checks as any submission (https, public address, not a top page) before anything is stored.
        $submissions->validatedUrl($url, new OfficialDomain(['host' => $host]));

        return $host;
    }

    public function retry(Request $request, OfficialLinkCandidate $candidate, ManualOfficialAnnouncement $submissions): RedirectResponse
    {
        $submissions->retry($candidate, $request->user('admin')->id);

        return redirect()->route('admin.official-announcements.index')->with('status', '再取得を受け付けました。');
    }
}
