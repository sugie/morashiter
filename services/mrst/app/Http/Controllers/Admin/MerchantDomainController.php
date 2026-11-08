<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MerchantDomain;
use App\Services\Crawling\RegistrableDomain;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shopping-site hosts for the extension's public index. Each host is tied to a registered company and
 * an https evidence URL (the company's own page naming the shop). Rows are disabled, never deleted.
 */
class MerchantDomainController extends Controller
{
    private const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** Reserved or local-only top-level names that can never be a public shop. */
    private const RESERVED_TLDS = ['localhost', 'local', 'test', 'invalid', 'example', 'internal', 'home', 'lan'];

    public function index(): View
    {
        return view('admin.merchant-domains.index', [
            'domains' => MerchantDomain::query()->with(['company', 'verifiedBy'])
                ->orderByRaw('disabled_at is not null')->orderBy('registrable_domain')->orderBy('host')->get(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['host' => self::normalizeHost((string) $request->input('host'))]);
        $validated = $request->validate([
            'host' => ['required', 'string', 'regex:'.self::HOST_PATTERN, $this->publicHostRule(), 'unique:merchant_domains,host'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'evidence_url' => ['required', 'string', 'max:2048', 'url:https'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'host.regex' => 'ホスト名だけを入力してください（例：www.example.co.jp）。https://、パス、ポート、IP アドレスは使えません。',
            'host.unique' => 'このホストは登録済みです。',
            'evidence_url.url' => '根拠の URL は https:// で始まる URL を入力してください。',
        ]);

        MerchantDomain::query()->create([
            'host' => $validated['host'],
            'registrable_domain' => (string) RegistrableDomain::of($validated['host']),
            'company_id' => (int) $validated['company_id'],
            'evidence_url' => $validated['evidence_url'],
            'note' => $validated['note'] ?? null,
            'verified_by_admin_id' => $request->user('admin')->id,
            'verified_at' => now(),
        ]);

        return redirect()->route('admin.merchant-domains.index')
            ->with('status', "{$validated['host']} を通販サイトのドメインとして登録しました。公開実行で拡張用のデータに反映されます。");
    }

    public function disable(MerchantDomain $merchantDomain): RedirectResponse
    {
        if ($merchantDomain->disabled_at === null) {
            $merchantDomain->update(['disabled_at' => now()]);
        }

        return redirect()->route('admin.merchant-domains.index')
            ->with('status', "{$merchantDomain->host} を無効にしました。公開実行で拡張用のデータから外れます。");
    }

    /**
     * Lower case, no surrounding spaces or trailing dot; an internationalised name becomes punycode.
     */
    private static function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host !== '' && preg_match('/[^\x00-\x7f]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    private function publicHostRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = (string) $value;
            $labels = explode('.', $host);
            if (in_array(end($labels), self::RESERVED_TLDS, true)) {
                $fail('このホストは公開のサイトではありません。');

                return;
            }
            // A bare public suffix (co.jp, com) would match every shop under it.
            if (RegistrableDomain::of($host) === null) {
                $fail('co.jp や com のような公開サフィックスだけは登録できません。店舗のホスト名を入力してください。');
            }
        };
    }
}
