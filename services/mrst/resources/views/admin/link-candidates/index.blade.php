<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公式発表リンクの確認 | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; text-decoration: none; margin-right: 1rem; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #d7dfe7; margin-bottom: 2rem; }
        th, td { padding: .6rem .8rem; border-bottom: 1px solid #e7edf2; text-align: left; vertical-align: top; }
        th { background: #eef3f6; font-size: .9rem; }
        a.url-link { color: #0b5f87; word-break: break-all; font-size: .85rem; }
        ul.samples { margin: 0; padding-left: 1rem; }
        .note { background: #fff8e5; border-left: 4px solid #c38b1b; padding: .8rem 1rem; margin-bottom: 1rem; font-size: .9rem; }
        .status { background: #e8f3ec; border-left: 4px solid #2f7d4f; padding: .8rem 1rem; margin-bottom: 1rem; }
        .errors { background: #fdecec; border-left: 4px solid #b3261e; padding: .8rem 1rem; margin-bottom: 1rem; }
        form.inline { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; margin: .3rem 0; }
        input[type=text] { padding: .35rem .5rem; border: 1px solid #b9c6d2; border-radius: .25rem; font: inherit; }
        .actions button { padding: .35rem .7rem; border: 1px solid #164c66; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        .actions button.secondary { background: white; color: #164c66; }
        header button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Morashiter 管理画面</strong>
        <a href="{{ route('admin.dashboard') }}">ダッシュボード</a>
        <a href="{{ route('admin.articles.index') }}">記事一覧</a>
        <a href="{{ route('admin.link-candidates.index') }}">公式発表リンク</a>
        <a href="{{ route('admin.reviews.index') }}">審査キュー</a>
    </div>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>公式発表リンクの確認</h1>
    <p class="note">ニュース記事に含まれていた外部リンクです。リンク先が<strong>その企業自身のサイト</strong>だと確認できたホストだけを公式ドメインとして登録してください。登録したホストだけが一次資料として取得されます。</p>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <h2>確認待ちのホスト</h2>
    <table>
        <thead>
        <tr><th>ホスト</th><th>件数</th><th>リンク例</th><th>操作</th></tr>
        </thead>
        <tbody>
        @forelse ($groups as $group)
            <tr>
                <td>{{ $group->host }}</td>
                <td>{{ $group->candidate_count }}</td>
                <td>
                    <ul class="samples">
                        @foreach ($samples[$group->host] ?? [] as $sample)
                            <li><a class="url-link" href="{{ $sample->url }}" rel="noopener noreferrer" target="_blank">{{ $sample->url }}</a> {{ $sample->anchor_text }}</li>
                        @endforeach
                    </ul>
                </td>
                <td class="actions">
                    <form class="inline" method="post" action="{{ route('admin.official-domains.store') }}">
                        @csrf
                        <input type="hidden" name="host" value="{{ $group->host }}">
                        <input type="text" name="company_name" placeholder="企業名" required aria-label="{{ $group->host }} の企業名">
                        <button type="submit">公式として登録</button>
                    </form>
                    <form class="inline" method="post" action="{{ route('admin.link-candidates.reject-host') }}">
                        @csrf
                        <input type="hidden" name="host" value="{{ $group->host }}">
                        <button type="submit" class="secondary">対象外</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" style="padding: 2rem; text-align: center; color: #596877;">確認待ちのリンクはありません。</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>登録済みの公式ドメイン</h2>
    <table>
        <thead>
        <tr><th>ホスト</th><th>企業</th><th>登録日時</th></tr>
        </thead>
        <tbody>
        @forelse ($domains as $domain)
            <tr>
                <td>{{ $domain->host }}</td>
                <td>{{ $domain->company->name }}</td>
                <td>{{ $domain->verified_at?->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="padding: 2rem; text-align: center; color: #596877;">まだ登録はありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</main>
</body>
</html>
