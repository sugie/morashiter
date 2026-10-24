<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>訂正版の作成 | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; text-decoration: none; margin-right: 1rem; }
        header button { padding: .4rem .8rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
        main { max-width: 60rem; margin: 2rem auto; padding: 0 1rem; }
        .note { background: #fff8e5; border-left: 4px solid #c38b1b; padding: .8rem 1rem; margin-bottom: 1rem; font-size: .9rem; }
        .errors { background: #fdecec; border-left: 4px solid #b3261e; padding: .8rem 1rem; margin-bottom: 1rem; }
        form { background: white; border: 1px solid #d7dfe7; border-radius: .4rem; padding: 1.2rem; }
        label { display: block; margin-top: .9rem; font-weight: 600; font-size: .92rem; }
        small { color: #596877; font-weight: normal; }
        input[type=text], input[type=password], select, textarea { width: 100%; box-sizing: border-box; padding: .45rem .55rem; border: 1px solid #b9c6d2; border-radius: .25rem; font: inherit; margin-top: .25rem; }
        textarea { min-height: 4.5rem; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
        a.url-link { color: #0b5f87; word-break: break-all; }
        button.primary { margin-top: 1.2rem; padding: .55rem 1.2rem; border: 1px solid #164c66; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        @media (max-width: 640px) { .row { grid-template-columns: 1fr; } }
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
    <form method="post" action="{{ route('admin.logout') }}" style="background:none;border:0;padding:0">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>訂正版の作成 {{ $incident->display_code }}</h1>
    <p class="note">
        公式発表：<a class="url-link" href="{{ $revision->source }}" rel="noopener noreferrer" target="_blank">{{ $revision->source }}</a><br>
        現在の第{{ $revision->revision_number }}版の内容を初期値にしています。直した内容は<strong>第{{ $revision->revision_number + 1 }}版</strong>として保存され、元の版は履歴として残ります（公開は「公開実行」で反映）。
    </p>
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <form method="post" action="{{ route('admin.incidents.correct.store', $incident) }}">
        @csrf
        @include('admin.partials.revision-fields')
        <label>パスワード（再入力） <small>訂正操作の確認</small><input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit" class="primary">この内容で訂正版を作成</button>
    </form>
</main>
</body>
</html>
