<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>記事一覧 | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; text-decoration: none; margin-right: 1rem; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .toolbar form { display: flex; gap: .5rem; align-items: center; }
        table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #d7dfe7; }
        th, td { padding: .6rem .8rem; border-bottom: 1px solid #e7edf2; text-align: left; vertical-align: top; }
        th { background: #eef3f6; font-size: .9rem; }
        tbody tr:hover { background: #f8fbfd; }
        a.url-link { color: #0b5f87; word-break: break-all; font-size: .85rem; }
        .pager { margin-top: 1rem; }
        .note { background: #fff8e5; border-left: 4px solid #c38b1b; padding: .8rem 1rem; margin-bottom: 1rem; font-size: .9rem; }
        button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Morashiter 管理画面</strong>
        <a href="{{ route('admin.dashboard') }}">ダッシュボード</a>
        <a href="{{ route('admin.articles.index') }}">記事一覧</a>
    </div>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>記事一覧</h1>
    <p class="note">これらは収集した一次資料のメタデータであり、公表承認済みの情報ではありません。本文や原文は非公開ストレージに保存され、この一覧には出さない方針です。</p>

    <div class="toolbar">
        <form method="get" action="{{ route('admin.articles.index') }}">
            <label>媒体で絞り込み:
                <select name="source_id" onchange="this.form.submit()">
                    <option value="">すべて</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source->id }}" @selected($activeSourceId === $source->id)>{{ $source->name }}</option>
                    @endforeach
                </select>
            </label>
            <noscript><button type="submit">適用</button></noscript>
        </form>
        <span>{{ $documents->total() }} 件</span>
    </div>

    <table>
        <thead>
        <tr>
            <th>取得日時</th>
            <th>媒体</th>
            <th>タイトル</th>
            <th>公表日</th>
            <th>取得URL</th>
            <th>抽出版</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($documents as $document)
            <tr>
                <td>{{ $document->fetched_at?->format('Y-m-d H:i') }}</td>
                <td>{{ $document->source?->name ?? '(未設定)' }}</td>
                <td>{{ $document->title }}</td>
                <td>{{ $document->published_on?->toDateString() ?? '—' }}</td>
                <td><a class="url-link" href="{{ $document->canonical_url }}" rel="noopener noreferrer" target="_blank">{{ $document->canonical_url }}</a></td>
                <td>{{ $document->extraction_version }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="padding: 2rem; text-align: center; color: #596877;">まだ記事がありません。</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="pager">{{ $documents->links() }}</div>
</main>
</body>
</html>
