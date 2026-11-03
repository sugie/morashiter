<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理画面 | Morashiter</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        main { max-width: 72rem; margin: 2rem auto 4rem; padding: 0 1rem; }
        button { padding: .55rem .9rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
        a { color: #0b5f87; }
        h1 { margin-bottom: .4rem; }
        h2 { font-size: 1.25rem; margin: 0 0 1rem; }
        .muted { color: #526575; font-size: .9rem; }
        .cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin: 1.5rem 0; }
        .card { display: block; background: white; border: 1px solid #d7dfe7; border-radius: .5rem; padding: 1.2rem; color: inherit; text-decoration: none; }
        .card:hover, .card:focus-visible { border-color: #164c66; outline: 2px solid #164c66; outline-offset: 2px; }
        .card h2 { font-size: 1rem; margin: 0; }
        .card .number { display: block; font-size: 2.1rem; font-weight: bold; margin: .5rem 0; font-variant-numeric: tabular-nums; }
        .card p { margin: .4rem 0 0; font-size: .9rem; }
        .attention { border-top: 4px solid #bd8521; }
        .error { border-top: 4px solid #b3261e; }
        .panel { background: white; border: 1px solid #d7dfe7; border-radius: .5rem; padding: 1.3rem; margin: 1.2rem 0; }
        .notice { padding: .8rem 1rem; background: #fff8e5; border-left: 4px solid #bd8521; }
        .good { padding: .8rem 1rem; background: #e8f3ec; border-left: 4px solid #2f7d4f; }
        .pipeline { display: flex; flex-wrap: wrap; gap: .75rem 2rem; padding: 0; list-style: none; }
        .menu { display: flex; flex-wrap: wrap; gap: .8rem 1.5rem; padding: 0; list-style: none; }
        @media (max-width: 760px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 480px) { .cards { grid-template-columns: 1fr; } header { padding: 1rem; gap: 1rem; flex-wrap: wrap; } }
    </style>
</head>
<body>
<header>
    <strong>Morashiter 管理画面</strong>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>ダッシュボード</h1>
    <p class="muted">ニュースの取得から公開まで、確認が必要な作業をまとめています。再読み込みすると最新の件数になります。</p>

    <section class="cards" aria-label="確認が必要な作業">
        <a class="card {{ $counts['pending_hosts'] > 0 ? 'attention' : '' }}" href="{{ route('admin.link-candidates.index') }}">
            <h2>公式発表リンクの確認待ち</h2>
            <span class="number">{{ $counts['pending_hosts'] }} <small>ホスト</small></span>
            <p>候補リンク {{ $counts['pending_links'] }} 件。組織名と公式サイトを確認する</p>
        </a>
        <a class="card {{ $counts['review_queue'] > 0 ? 'attention' : '' }}" href="{{ route('admin.reviews.index') }}">
            <h2>審査キュー</h2>
            <span class="number">{{ $counts['review_queue'] }} <small>件</small></span>
            <p>審査待ち {{ $counts['review_ready'] }} 件 ／ 解析失敗 {{ $counts['analysis_failed'] }} 件</p>
        </a>
        <a class="card {{ $hasUnpublishedChanges ? 'attention' : '' }}" href="{{ route('admin.publications.index') }}">
            <h2>公開待ち（新規）</h2>
            <span class="number">{{ $counts['publish_new'] }} <small>件</small></span>
            <p>承認済みで、現在の公開版に含まれていない記事</p>
        </a>
        <a class="card {{ $counts['missing_links'] > 0 ? 'attention' : '' }}" href="{{ route('admin.link-candidates.index') }}#retrieval-issues">
            <h2>公式発表が見つからない</h2>
            <span class="number">{{ $counts['missing_links'] }} <small>件</small></span>
            <p>元ニュースを確認し、個別の公式発表URLを追加する</p>
        </a>
        <a class="card {{ $counts['fetch_failed'] > 0 ? 'error' : '' }}" href="{{ route('admin.link-candidates.index') }}#retrieval-issues">
            <h2>公式発表の取得失敗</h2>
            <span class="number">{{ $counts['fetch_failed'] }} <small>件</small></span>
            <p>取得先のURLとエラーを確認する</p>
        </a>
        <a class="card {{ $counts['analysis_failed'] > 0 ? 'error' : '' }}" href="{{ route('admin.reviews.index') }}">
            <h2>AI解析失敗</h2>
            <span class="number">{{ $counts['analysis_failed'] }} <small>件</small></span>
            <p>審査キューの内数。本文の抽出失敗などを確認する</p>
        </a>
    </section>

    <section class="panel" aria-labelledby="processing-title">
        <h2 id="processing-title">取得・解析の進み具合</h2>
        <ul class="pipeline">
            <li>公式発表の取得待ち <strong>{{ $counts['fetch_waiting'] }} 件</strong></li>
            <li>取得中 <strong>{{ $counts['fetch_running'] }} 件</strong></li>
            <li>AI解析待ち・解析中 <strong>{{ $counts['analysis_waiting'] }} 件</strong></li>
        </ul>
        <p class="muted">AI解析待ち・解析中は、取得済みで解析結果がまだない資料です。各段階の件数は同じ記事を含むため、合計が記事数になるとは限りません。</p>
        <a href="{{ route('admin.official-announcements.index') }}">公式発表URLを追加・進捗を確認</a>
    </section>

    <section class="panel" aria-labelledby="publication-title">
        <h2 id="publication-title">公開の状況</h2>
        @if ($hasUnpublishedChanges)
            <p class="notice">公開に未反映の変更があります。新規記事のほか、訂正・撤回も公開操作で反映されます。</p>
        @elseif ($liveRelease)
            <p class="good">承認済みの内容は公開に反映済みです。</p>
        @else
            <p>まだ公開対象の記事はありません。</p>
        @endif
        @if (! $publishingEnabled)<p class="notice">公開機能は無効です。設定を確認してください。</p>@endif
        @if ($latestRelease)
            <p>直近の公開処理：<strong>{{ ['building' => '公開処理中（生成中）', 'built' => '公開処理中（生成済み）', 'uploaded' => '公開処理中（反映確認中）', 'live' => '公開成功', 'failed' => '公開失敗', 'skipped' => '変更なし', 'superseded' => '旧公開版'][$latestRelease->status] ?? $latestRelease->status }}</strong>
                ／ {{ $latestRelease->created_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST</p>
        @else
            <p>公開履歴はありません。</p>
        @endif
        <p class="muted">承認と公開は別の操作です。新規件数は現在の公開版と照合し、訂正・撤回は内容の変更として確認しています。</p>
        <a href="{{ route('admin.publications.index') }}">公開画面で確認する</a>
    </section>

    <section class="panel" aria-labelledby="crawler-title">
        <h2 id="crawler-title">直近のニュース取得</h2>
        @if ($latestCrawl)
            <p>{{ $latestCrawl->source?->name }} ／ {{ ['queued' => '待機中', 'running' => '実行中', 'succeeded' => '完了', 'failed' => '失敗'][$latestCrawl->status] ?? $latestCrawl->status }}</p>
            <p>開始：{{ $latestCrawl->started_at?->timezone('Asia/Tokyo')->format('Y/m/d H:i') ?? '未開始' }} JST</p>
            <p>新規発見 {{ $latestCrawl->discovered_count }} 件 ／ スキップ {{ $latestCrawl->skipped_count }} 件 ／ 取得成功 {{ $latestCrawl->succeeded_count }} 件 ／ 失敗 {{ $latestCrawl->failed_count }} 件</p>
            @if ($latestCrawl->error_code)<p>エラー：{{ $latestCrawl->error_code }}</p>@endif
        @else
            <p>ニュース取得の履歴はありません。</p>
        @endif
        <a href="{{ route('admin.articles.index') }}">取得した記事を見る</a>
    </section>

    <nav aria-label="管理メニュー"><ul class="menu">
        <li><a href="{{ route('admin.articles.index') }}">記事一覧</a></li>
        <li><a href="{{ route('admin.link-candidates.index') }}">公式発表リンクの確認</a></li>
        <li><a href="{{ route('admin.official-announcements.index') }}">公式発表URLを手動追加・進捗確認</a></li>
        <li><a href="{{ route('admin.reviews.index') }}">審査キュー</a></li>
        <li><a href="{{ route('admin.incidents.index') }}">インシデント</a></li>
        <li><a href="{{ route('admin.publications.index') }}">公開</a></li>
    </ul></nav>
</main>
</body>
</html>
