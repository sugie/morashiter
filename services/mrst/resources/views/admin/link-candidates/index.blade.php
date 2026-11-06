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
        input[type=text], select { padding: .35rem .5rem; border: 1px solid #b9c6d2; border-radius: .25rem; font: inherit; }
        .actions button { padding: .35rem .7rem; border: 1px solid #164c66; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        .actions button.secondary { background: white; color: #164c66; }
        header button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }

        header { gap: 1rem; }
        header > div { min-width: 0; }
        header > form { flex-shrink: 0; }
        header button { white-space: nowrap; }
        @media (max-width: 640px) {
            header { flex-wrap: wrap; padding: 1rem; }
            header > div { display: flex; flex-wrap: wrap; gap: .5rem 1rem; flex-basis: 100%; }
            header strong { flex-basis: 100%; }
            header a { margin-right: 0; }
            table, tbody, tr, td { display: block; box-sizing: border-box; width: 100%; }
            table { border: 0; background: transparent; overflow-wrap: anywhere; }
            thead { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
            tbody tr { background: white; border: 1px solid #d7dfe7; margin-bottom: 1rem; }
            td:last-child { border-bottom: 0; }
            td[data-label]::before { content: attr(data-label); display: block; margin-bottom: .4rem; font-weight: bold; color: #164c66; }
            a.url-link { word-break: normal; }
            .actions input[type=text], .actions select, .actions textarea { box-sizing: border-box; max-width: 100%; width: 100%; font: inherit; }
            .actions textarea { min-height: 5rem; }
            .actions button { max-width: 100%; margin-bottom: .4rem; }
            blockquote { margin: .75rem 1rem; }
        }
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
    <p><a href="{{ route('admin.official-announcements.index') }}">公式発表URLを手動追加・進捗確認</a></p>
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
                <td data-label="ホスト">{{ $group->host }}</td>
                <td data-label="件数">{{ $group->candidate_count }}</td>
                <td data-label="リンク例">
                    <ul class="samples">
                        @foreach ($samples[$group->host] ?? [] as $sample)
                            <li><a class="url-link" href="{{ $sample->url }}" rel="noopener noreferrer" target="_blank">{{ $sample->url }}</a>
                                <div>リンク表記：{{ $sample->anchor_text ?: 'なし' }}</div>
                                @if ($sample->sourceDocument)<div>元ニュース：{{ $sample->sourceDocument->title }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                </td>
                <td class="actions" data-label="操作">
                    <p><small>企業名は候補（AI調査・リンク表記・登録済み企業）から選びます。候補は未確認なので、正式名称を確認してください。合わないときは「その他」を選んで入力します。</small></p>
                    @include('admin.link-candidates.research', ['research' => $researches[$group->host] ?? null, 'decision' => $decisions[$group->host] ?? null, 'suggestions' => $companySuggestions[$group->host] ?? []])
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

    <h2 id="retrieval-issues">取得失敗・公式発表が見つからない候補</h2>
    <p>公式ドメインの確認後も、取得失敗や発表の探索失敗が残っている候補です。元ニュースと同じ事件の個別発表を確認し、<a href="{{ route('admin.official-announcements.index') }}">公式発表URLを手動追加</a>してください。手動追加後も元の探索記録は残ります。</p>
    <table>
        <thead><tr><th>候補・リンク表記</th><th>元ニュース</th><th>状態</th></tr></thead>
        <tbody>
        @forelse ($retrievalIssues as $issue)
            <tr>
                <td data-label="候補・リンク表記">#{{ $issue->id }} {{ $issue->anchor_text }}<br><a class="url-link" href="{{ $issue->url }}" target="_blank" rel="noopener noreferrer">{{ $issue->url }}</a></td>
                <td data-label="元ニュース">{{ $issue->sourceDocument?->title ?? '手動追加' }}</td>
                <td data-label="状態">{{ $issue->status === 'failed' ? '取得失敗' : '公式発表が見つからない' }}<br><small>{{ $issue->error_code }}</small></td>
            </tr>
        @empty
            <tr><td colspan="3">取得失敗・探索失敗はありません。</td></tr>
        @endforelse
        </tbody>
    </table>
    <nav aria-label="取得失敗・探索失敗のページ">
        @if ($retrievalIssues->previousPageUrl())<a href="{{ $retrievalIssues->previousPageUrl() }}">前へ</a>@endif
        <span>{{ $retrievalIssues->currentPage() }} / {{ $retrievalIssues->lastPage() }} ページ（全 {{ $retrievalIssues->total() }} 件）</span>
        @if ($retrievalIssues->nextPageUrl())<a href="{{ $retrievalIssues->nextPageUrl() }}">次へ</a>@endif
    </nav>

    <h2>登録済みの公式ドメイン</h2>
    <table>
        <thead>
        <tr><th>ホスト</th><th>企業</th><th>登録日時</th></tr>
        </thead>
        <tbody>
        @forelse ($domains as $domain)
            <tr>
                <td data-label="ホスト">{{ $domain->host }}</td>
                <td data-label="企業">{{ $domain->company->name }}</td>
                <td data-label="登録日時">{{ $domain->verified_at?->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="padding: 2rem; text-align: center; color: #596877;">まだ登録はありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</main>
</body>
</html>
