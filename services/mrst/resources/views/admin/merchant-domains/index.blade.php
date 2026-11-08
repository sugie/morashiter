<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>通販サイトのドメイン | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; text-decoration: none; margin-right: 1rem; }
        header button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        .note { background: #fff8e5; border-left: 4px solid #c38b1b; padding: .8rem 1rem; margin-bottom: 1rem; font-size: .9rem; }
        .status { background: #e8f3ec; border-left: 4px solid #2f7d4f; padding: .8rem 1rem; margin-bottom: 1rem; }
        .errors { background: #fdecec; border-left: 4px solid #b3261e; padding: .8rem 1rem; margin-bottom: 1rem; }
        form.register { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: .8rem 1rem; background: white; border: 1px solid #d7dfe7; border-radius: .4rem; padding: 1rem; margin-bottom: 1.5rem; }
        form.register label { display: flex; flex-direction: column; gap: .3rem; font-size: .9rem; }
        form.register input, form.register select { padding: .45rem .55rem; border: 1px solid #b9c6d2; border-radius: .25rem; font: inherit; }
        form.register .actions { grid-column: 1 / -1; }
        button.primary { padding: .5rem 1.1rem; border: 1px solid #164c66; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        button.secondary { padding: .3rem .7rem; border: 1px solid #8a99a8; border-radius: .25rem; background: white; color: #17283b; font: inherit; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #d7dfe7; }
        th, td { padding: .55rem .8rem; border-bottom: 1px solid #e7edf2; text-align: left; vertical-align: top; font-size: .92rem; }
        th { background: #eef3f6; }
        tr.disabled td { color: #7a8794; }
        .muted { color: #596877; font-size: .85rem; }
        .badge { display: inline-block; padding: .05rem .45rem; border-radius: .25rem; font-size: .8rem; }
        .badge.on { background: #e8f3ec; color: #2f7d4f; }
        .badge.off { background: #eef0f2; color: #596877; }
        .scroll { overflow-x: auto; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Morashiter 管理画面</strong>
        <a href="{{ route('admin.dashboard') }}">ダッシュボード</a>
        <a href="{{ route('admin.incidents.index') }}">インシデント</a>
        <a href="{{ route('admin.publications.index') }}">公開</a>
    </div>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>通販サイトのドメイン（拡張用）</h1>
    <p class="note">
        お客さんが買い物をするサイトのホスト（例：www.example-shop.co.jp）を、運営している会社に結び付けます。公式発表を取得する「公式ドメイン」とは別の一覧です。
        拡張はホストの<strong>完全一致</strong>だけで照合するので、別のホスト（tour.〜、shop.〜 など）で同じ店舗を表示したい場合は、それぞれ登録してください。
        根拠の URL には、その会社自身のページで、この通販サイトを運営していることが分かる https のページを入れてください。
        登録・無効化は、次の公開実行で拡張用のデータに反映されます。
    </p>
    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <h2>登録</h2>
    <form class="register" method="post" action="{{ route('admin.merchant-domains.store') }}">
        @csrf
        <label>ホスト
            <input type="text" name="host" value="{{ old('host') }}" maxlength="253" required placeholder="www.example-shop.co.jp" autocomplete="off">
        </label>
        <label>運営している会社（登録済みの企業から選ぶ）
            <select name="company_id" required>
                <option value="">選択してください</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((string) old('company_id') === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </select>
        </label>
        <label>根拠の URL（https）
            <input type="url" name="evidence_url" value="{{ old('evidence_url') }}" maxlength="2048" required placeholder="https://www.example.co.jp/company/">
        </label>
        <label>メモ（任意）
            <input type="text" name="note" value="{{ old('note') }}" maxlength="1000" placeholder="会社概要の「運営サイト」に記載 など">
        </label>
        <div class="actions"><button type="submit" class="primary">登録する</button></div>
    </form>

    <h2>登録済み（{{ $domains->count() }} 件）</h2>
    <div class="scroll">
    <table>
        <thead><tr><th>ホスト</th><th>登録ドメイン</th><th>会社</th><th>根拠</th><th>確認</th><th>状態</th></tr></thead>
        <tbody>
        @forelse ($domains as $domain)
            <tr class="{{ $domain->disabled_at ? 'disabled' : '' }}">
                <td><code>{{ $domain->host }}</code>@if ($domain->note)<div class="muted">{{ $domain->note }}</div>@endif</td>
                <td><code>{{ $domain->registrable_domain }}</code></td>
                <td>{{ $domain->company?->name }}</td>
                <td><a href="{{ $domain->evidence_url }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($domain->evidence_url, 60) }}</a></td>
                <td class="muted">{{ $domain->verifiedBy?->name ?? '—' }}<br>{{ $domain->verified_at?->setTimezone('Asia/Tokyo')->format('Y-m-d H:i') }}</td>
                <td>
                    @if ($domain->disabled_at)
                        <span class="badge off">無効</span>
                        <div class="muted">{{ $domain->disabled_at->setTimezone('Asia/Tokyo')->format('Y-m-d H:i') }}</div>
                    @else
                        <span class="badge on">有効</span>
                        <form method="post" action="{{ route('admin.merchant-domains.disable', $domain) }}" style="margin-top:.4rem">
                            @csrf
                            <button type="submit" class="secondary">無効にする</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="padding: 2rem; text-align: center; color: #596877;">まだ登録していません。</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</main>
</body>
</html>
