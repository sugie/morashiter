<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Crawling\OfficialDomainResearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfficialDomainResearchController extends Controller
{
    public function request(Request $request, OfficialDomainResearch $research): RedirectResponse
    {
        $data = $request->validate(['host' => ['required', 'string', 'max:253']]);
        abort_unless(config('domain_research.enabled'), 409, 'AI調査は停止中です。');
        $item = $research->request($data['host'], true);
        abort_if($item === null, 409, '確認待ちのホストがありません。');

        return redirect()->route('admin.link-candidates.index')->with('status', 'AI調査を予約しました。結果を確認してから登録してください。');
    }

    public function decide(Request $request, OfficialDomainResearch $research): RedirectResponse
    {
        $request->merge(['host' => strtolower(trim((string) $request->input('host')))]);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'investigation_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['register', 'hold'])],
            'company_name' => ['required_if:decision,register', 'nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence_checked' => ['accepted_if:decision,register'],
            'ownership_checked' => ['accepted_if:decision,register'],
            'name_checked' => ['accepted_if:decision,register'],
            'batch_checked' => ['accepted_if:decision,register'],
        ]);
        $research->decide($data, $request->user('admin')->id);

        return redirect()->route('admin.link-candidates.index')->with('status', $data['decision'] === 'register' ? '確認結果を記録して公式ドメインを登録しました。' : '保留理由を記録しました。');
    }
}
