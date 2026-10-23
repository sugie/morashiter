<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OfficialDomain;
use App\Models\OfficialDomainInvestigation;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\OfficialDomainResearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Lets an administrator decide which hosts found in news articles are a company's own site.
 * Only hosts registered here are later fetched as official sources.
 */
class OfficialLinkReviewController extends Controller
{
    private const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function index(): View
    {
        $groups = OfficialLinkCandidate::query()
            ->where('status', 'pending')
            ->select('host', DB::raw('count(*) as candidate_count'), DB::raw('max(created_at) as latest_at'))
            ->groupBy('host')
            ->orderByDesc('candidate_count')
            ->orderBy('host')
            ->limit(100)
            ->get();

        $samples = OfficialLinkCandidate::query()
            ->where('status', 'pending')
            ->whereIn('host', $groups->pluck('host'))
            ->orderByDesc('id')
            ->get(['host', 'url', 'anchor_text'])
            ->groupBy('host')
            ->map(fn ($candidates) => $candidates->take(3));

        return view('admin.link-candidates.index', [
            'groups' => $groups,
            'researches' => OfficialDomainInvestigation::query()->whereIn('id', OfficialDomainInvestigation::query()->selectRaw('max(id)')->whereIn('host', $groups->pluck('host'))->groupBy('host'))->get()->keyBy('host'),
            'decisions' => DB::table('official_domain_decisions')->whereIn('id', DB::table('official_domain_decisions')->selectRaw('max(id)')->whereIn('host', $groups->pluck('host'))->groupBy('host'))->get()->keyBy('host'),
            'companies' => Company::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'samples' => $samples,
            'domains' => OfficialDomain::query()->with('company')->latest('verified_at')->limit(50)->get(),
        ]);
    }

    public function storeDomain(Request $request): RedirectResponse
    {
        $request->merge(['host' => strtolower(trim((string) $request->input('host')))]);
        $validated = $request->validate([
            'host' => ['required', 'string', 'regex:'.self::HOST_PATTERN, 'unique:official_domains,host'],
            'company_name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($validated, $request): void {
            OfficialDomainResearch::lockHost($validated['host']);
            abort_if(OfficialDomainInvestigation::query()->where('host', $validated['host'])->exists(), 409, '調査結果の確認フォームから登録してください。');
            $company = Company::query()->firstOrCreate(['name' => trim($validated['company_name'])]);
            OfficialDomain::query()->create([
                'company_id' => $company->id,
                'host' => $validated['host'],
                'verified_by_admin_id' => $request->user('admin')->id,
                'verified_at' => now(),
            ]);
            OfficialLinkCandidate::query()
                ->where('host', $validated['host'])
                ->where('status', 'pending')
                ->update(['status' => 'accepted', 'updated_at' => now()]);
        });

        return redirect()->route('admin.link-candidates.index')
            ->with('status', "{$validated['host']} を公式ドメインとして登録しました。");
    }

    public function rejectHost(Request $request): RedirectResponse
    {
        $request->merge(['host' => strtolower(trim((string) $request->input('host')))]);
        $validated = $request->validate(['host' => ['required', 'string', 'max:253']]);

        DB::transaction(function () use ($validated): void {
            OfficialDomainResearch::lockHost($validated['host']);
            OfficialLinkCandidate::query()->where('host', $validated['host'])->where('status', 'pending')
                ->update(['status' => 'rejected', 'updated_at' => now()]);
        });

        return redirect()->route('admin.link-candidates.index')
            ->with('status', "{$validated['host']} の候補を対象外にしました。");
    }
}
