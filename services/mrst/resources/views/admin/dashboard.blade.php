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
        main { max-width: 48rem; margin: 3rem auto; padding: 0 1rem; }
        button { padding: .55rem .9rem; border: 1px solid white; border-radius: .25rem; background: transparent; color: white; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <strong>Morashiter 管理画面</strong>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>管理画面</h1>
    <p>管理者認証と接続元IP制限が有効です。運用機能は今後追加します。</p>
    <ul>
        <li><a href="{{ route('admin.articles.index') }}">記事一覧</a></li>
    </ul>
</main>
</body>
</html>
