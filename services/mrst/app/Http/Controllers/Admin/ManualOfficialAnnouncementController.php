<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\ManualOfficialAnnouncement;
use App\Services\Crawling\OfficialFetchGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManualOfficialAnnouncementController extends Controller
{
    public function index(OfficialFetchGate $gate): View
    {
        $submissions = OfficialLinkCandidate::query()->whereNull('source_document_id')->latest('id')->paginate(25);

        return view('admin.link-candidates.manual', [
            'submissions' => $submissions,
            'documents' => OfficialDocument::query()->with('review')->whereIn('url_hash', $submissions->pluck('url_hash'))->latest('id')->get()->groupBy('url_hash'),
            'domains' => OfficialDomain::query()->with('company')->orderBy('host')->get(),
            'unresolved' => OfficialLinkCandidate::query()->with('sourceDocument')->where('error_code', 'no_incident_link')->orderBy('id')->paginate(25, ['*'], 'unresolved_page'),
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
        ]);
        $candidate = $submissions->submit($data['url'], OfficialDomain::query()->findOrFail($data['official_domain_id']), $request->user('admin')->id);
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
