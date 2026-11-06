<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OfficialAnnouncementSearch;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\OfficialAnnouncementSearch as Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfficialAnnouncementSearchController extends Controller
{
    public function search(Request $request, OfficialLinkCandidate $candidate, Search $search): RedirectResponse
    {
        abort_unless(config('announcement_search.enabled'), 409, '公式発表のAI探索は停止中です。');
        $row = $search->request($candidate, $request->user('admin')->id);
        abort_if($row === null, 409, 'この候補は探索の対象ではありません。画面を更新してください。');

        return redirect()->route('admin.official-announcements.index')->with('status', '公式発表の探索を予約しました。数分後に画面を再読み込みしてください。');
    }

    public function dismiss(Request $request, OfficialAnnouncementSearch $search, Search $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $service->dismiss($search, $request->user('admin')->id, $data['reason']);

        return redirect()->route('admin.official-announcements.index')->with('status', '探索結果を閉じました（理由を記録しました）。');
    }
}
