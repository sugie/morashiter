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
        ]);
        $domain = OfficialDomain::query()->findOrFail($data['official_domain_id']);
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

    public function retry(Request $request, OfficialLinkCandidate $candidate, ManualOfficialAnnouncement $submissions): RedirectResponse
    {
        $submissions->retry($candidate, $request->user('admin')->id);

        return redirect()->route('admin.official-announcements.index')->with('status', '再取得を受け付けました。');
    }
}
