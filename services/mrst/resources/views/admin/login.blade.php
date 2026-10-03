<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理者ログイン | Morashiter</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; }
        main { width: min(100% - 2rem, 24rem); padding: 2rem; background: white; border: 1px solid #dce3e8; border-radius: .5rem; }
        h1 { margin: 0 0 .5rem; font-size: 1.4rem; }
        p { margin: 0 0 1.5rem; color: #5d6a77; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input { box-sizing: border-box; display: block; width: 100%; margin-top: .35rem; padding: .7rem; border: 1px solid #a8b5c0; border-radius: .25rem; font: inherit; }
        button { width: 100%; margin-top: 1.5rem; padding: .75rem; border: 0; border-radius: .25rem; background: #164c66; color: white; font: inherit; cursor: pointer; }
        .error { margin-top: 1rem; color: #a5292f; }
    </style>
</head>
<body>
<main>
    <h1>Morashiter 管理画面</h1>
    <p>管理者アカウントでログインしてください。</p>
    <form method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">メールアドレス</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
        <label for="password">パスワード</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        @error('email') <div class="error" role="alert">{{ $message }}</div> @enderror
        @error('password') <div class="error" role="alert">{{ $message }}</div> @enderror
        <button type="submit">ログイン</button>
    </form>
</main>
</body>
</html>
