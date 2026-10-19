<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>審査キュー | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; text-decoration: none; margin-right: 1rem; }
        header button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border: 1px solid #d7dfe7; border-radius: .4rem; padding: 1rem 1.2rem; margin-bottom: 1rem; }
        .card h2 { font-size: 1.05rem; margin: 0 0 .5rem; }
        dl { display: grid; grid-template-columns: 9rem 1fr; gap: .3rem .8rem; margin: .6rem 0; }
        dt { color: #596877; font-size: .9rem; }
        dd { margin: 0; }
        a.url-link { color: #0b5f87; word-break: break-all; font-size: .85rem; }
        .badge { display: inline-block; font-size: .8rem; padding: .1rem .5rem; border-radius: 1rem; background: #eef3f6; margin-right: .4rem; }
        .badge.failed { background: #fdecec; color: #b3261e; }
        .badge.mock { background: #fff8e5; color: #7a5a00; }
        .note { background: #fff8e5; border-left: 4px solid #c38b1b; padding: .8rem 1rem; margin-bottom: 1rem; font-size: .9rem; }
        .status { background: #e8f3ec; border-left: 4px solid #2f7d4f; padding: .8rem 1rem; margin-bottom: 1rem; }
        .actions { display: flex; gap: .5rem; }
        .actions button { padding: .35rem .9rem; border: 1px solid #164c66; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        .actions button.secondary { background: white; color: #164c66; }
        .actions button[disabled] { opacity: .45; cursor: not-allowed; }
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
    <h1>審査キュー</h1>
    <p class="note">企業・行政の公式発表から AI が作った下書きです。<strong>必ず公式発表と照らし合わせて</strong>から承認してください。mock の下書きは AI 未使用のため承認できません（AI 有効化後に自動で作り直されます）。</p>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @forelse ($reviews as $review)
        @php($analysis = $review->analysis ?? [])
        <section class="card">
            <h2>
                #{{ $review->id }}
                @if ($review->status === 'analysis_failed')
                    <span class="badge failed">解析失敗: {{ $review->error_code }}</span>
                @elseif ($review->analyzer === 'mock')
                    <span class="badge mock">mock</span>
                @else
                    <span class="badge">{{ $review->analyzer }}</span>
                @endif
                {{ $analysis['incident_title'] ?? $review->officialDocument->title ?? '(タイトルなし)' }}
            </h2>
            <dl>
                <dt>公式発表</dt>
                <dd><a class="url-link" href="{{ $review->officialDocument->url }}" rel="noopener noreferrer" target="_blank">{{ $review->officialDocument->url }}</a></dd>
                <dt>登録企業</dt>
                <dd>{{ $review->officialDocument->officialDomain?->company?->name ?? '—' }}</dd>
                <dt>組織（AI）</dt>
                <dd>{{ $analysis['organization'] ?? '—' }}</dd>
                <dt>影響規模</dt>
                <dd>{{ $analysis['affected_count_text'] ?? '—' }}</dd>
                <dt>対象情報</dt>
                <dd>{{ implode('、', $analysis['data_types'] ?? []) ?: '—' }}</dd>
                <dt>公表日</dt>
                <dd>{{ $analysis['disclosed_at'] ?? '—' }}</dd>
                <dt>対応状況</dt>
                <dd>{{ $analysis['response_status'] ?? '—' }}</dd>
                <dt>要約</dt>
                <dd>{{ $analysis['summary'] ?? '—' }}</dd>
            </dl>
            <div class="actions">
                <form method="post" action="{{ route('admin.reviews.approve', $review) }}">
                    @csrf
                    <button type="submit" @disabled($review->status !== 'pending_review' || $review->analyzer === 'mock')>承認</button>
                </form>
                <form method="post" action="{{ route('admin.reviews.reject', $review) }}">
                    @csrf
                    <button type="submit" class="secondary">却下</button>
                </form>
            </div>
        </section>
    @empty
        <p>審査待ちはありません。</p>
    @endforelse
</main>
</body>
</html>
