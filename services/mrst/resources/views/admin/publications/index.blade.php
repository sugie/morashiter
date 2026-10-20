<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公開 | Morashiter 管理画面</title>
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
        form.publish { display: flex; gap: .5rem; align-items: center; background: white; border: 1px solid #d7dfe7; border-radius: .4rem; padding: 1rem; margin-bottom: 1.5rem; }
        form.publish input { padding: .45rem .55rem; border: 1px solid #b9c6d2; border-radius: .25rem; font: inherit; }
        form.publish button { padding: .5rem 1.1rem; border: 1px solid #164c66; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #d7dfe7; }
        th, td { padding: .55rem .8rem; border-bottom: 1px solid #e7edf2; text-align: left; vertical-align: top; font-size: .92rem; }
        th { background: #eef3f6; }
        .live { color: #2f7d4f; font-weight: 700; }
        .failed { color: #b3261e; font-weight: 700; }
        code { font-size: .85rem; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Morashiter 管理画面</strong>
        <a href="{{ route('admin.reviews.index') }}">審査キュー</a>
        <a href="{{ route('admin.incidents.index') }}">インシデント</a>
        <a href="{{ route('admin.publications.index') }}">公開</a>
    </div>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>公開</h1>
    <p class="note">承認済みのインシデント（最新の承認版）と撤回のお知らせから静的サイトを作り、<a href="{{ $publicUrl }}" target="_blank" rel="noopener noreferrer">{{ $publicUrl }}</a> に反映します。失敗したときは直前の公開版に自動で戻します。</p>
    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    @if ($enabled)
        <form class="publish" method="post" action="{{ route('admin.publications.publish') }}">
            @csrf
            <input type="password" name="password" placeholder="パスワード（再入力）" autocomplete="current-password" required>
            <button type="submit">公開実行</button>
        </form>
    @else
        <p class="errors">公開は無効です（MSGN_ENABLED=false）。S3 と CDN のキーを設定してから有効にしてください。</p>
    @endif

    <h2>公開履歴</h2>
    <table>
        <thead><tr><th>#</th><th>リリース</th><th>状態</th><th>件数</th><th>データ時点</th><th>実行者</th><th>開始</th><th>エラー</th></tr></thead>
        <tbody>
        @forelse ($releases as $release)
            <tr>
                <td>{{ $release->id }}</td>
                <td><code>{{ $release->release_id ?? '—' }}</code></td>
                <td class="{{ $release->status }}">{{ $release->status }}</td>
                <td>{{ count($release->incident_ids ?? []) }}</td>
                <td>{{ $release->data_as_of ?? '—' }}</td>
                <td>{{ $release->triggeredBy?->name ?? 'system' }}</td>
                <td>{{ $release->started_at?->setTimezone('Asia/Tokyo')->format('Y-m-d H:i') }}</td>
                <td>{{ $release->error }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="padding: 2rem; text-align: center; color: #596877;">まだ公開していません。</td></tr>
        @endforelse
        </tbody>
    </table>
</main>
</body>
</html>
