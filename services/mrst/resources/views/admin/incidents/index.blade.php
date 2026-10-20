<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>インシデント | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; text-decoration: none; margin-right: 1rem; }
        header button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #d7dfe7; }
        th, td { padding: .6rem .8rem; border-bottom: 1px solid #e7edf2; text-align: left; vertical-align: top; }
        th { background: #eef3f6; font-size: .9rem; }
        .status { background: #e8f3ec; border-left: 4px solid #2f7d4f; padding: .8rem 1rem; margin-bottom: 1rem; }
        .errors { background: #fdecec; border-left: 4px solid #b3261e; padding: .8rem 1rem; margin-bottom: 1rem; }
        .withdrawn { color: #b3261e; font-weight: 600; }
        details form { display: grid; gap: .4rem; margin-top: .4rem; }
        input[type=text], input[type=password] { padding: .35rem .5rem; border: 1px solid #b9c6d2; border-radius: .25rem; font: inherit; }
        details button { padding: .35rem .7rem; border: 1px solid #b3261e; border-radius: .25rem; background: #b3261e; color: white; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <div>
        <strong>Morashiter 管理画面</strong>
        <a href="{{ route('admin.reviews.index') }}">審査キュー</a>
        <a href="{{ route('admin.incidents.index') }}">インシデント</a>
    </div>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>インシデント</h1>
    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif
    <table>
        <thead><tr><th>コード</th><th>名称・企業</th><th>公表日</th><th>版</th><th>状態</th></tr></thead>
        <tbody>
        @foreach ($incidents as $incident)
            @php($revision = $incident->currentRevision)
            <tr>
                <td>{{ $incident->display_code }}</td>
                <td>{{ $revision?->name }}<br><small>{{ $revision?->company }}</small></td>
                <td>{{ $revision?->date }}</td>
                <td>第{{ $revision?->revision_number }}版（{{ $revision?->approval_method }}）</td>
                <td>
                    @if ($incident->withdrawn_at)
                        <span class="withdrawn">撤回済み</span> {{ $incident->withdrawn_at->format('Y-m-d') }}<br><small>{{ $incident->withdrawal_reason }}</small>
                    @else
                        掲載対象
                        <details>
                            <summary>撤回する</summary>
                            <form method="post" action="{{ route('admin.incidents.withdraw', $incident) }}">
                                @csrf
                                <input type="text" name="reason" placeholder="撤回の理由（サイトに表示されます）" required>
                                <input type="password" name="password" placeholder="パスワード（再入力）" autocomplete="current-password" required>
                                <button type="submit">撤回</button>
                            </form>
                        </details>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</main>
</body>
</html>
