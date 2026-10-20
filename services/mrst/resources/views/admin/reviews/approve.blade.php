<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>承認内容の確認 | Morashiter 管理画面</title>
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
    </div>
    <form method="post" action="{{ route('admin.logout') }}" style="background:none;border:0;padding:0">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>承認内容の確認 #{{ $review->id }}</h1>
    <p class="note">
        公式発表：<a class="url-link" href="{{ $review->officialDocument->url }}" rel="noopener noreferrer" target="_blank">{{ $review->officialDocument->url }}</a><br>
        AI の下書きを初期値にしています。<strong>すべての項目を公式発表と照らして</strong>直してから承認してください。承認した内容がそのままサイトに載ります（公開は「公開実行」で反映）。
    </p>
    @if ($errors->any())
        <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    @endif

    <form method="post" action="{{ route('admin.reviews.approve.store', $review) }}">
        @csrf
        <label>掲載先 <small>新規か、既存インシデントの続報・訂正か</small>
            <select name="incident_id">
                <option value="">新しいインシデントとして登録</option>
                @foreach ($incidents as $incident)
                    <option value="{{ $incident->id }}" @selected(old('incident_id') == $incident->id)>{{ $incident->display_code }} {{ $incident->currentRevision?->name }}（{{ $incident->currentRevision?->company }}）の新しい版</option>
                @endforeach
            </select>
        </label>
        <div class="row">
            <label>サービス名・表示名<input type="text" name="name" value="{{ old('name', $draft['name']) }}" required></label>
            <label>企業・組織名<input type="text" name="company" value="{{ old('company', $draft['company']) }}" required></label>
        </div>
        <div class="row">
            <label>種別
                <select name="type">@foreach ($types as $type)<option @selected(old('type', $draft['type']) === $type)>{{ $type }}</option>@endforeach</select>
            </label>
            <label>漏えい区分
                <select name="disclosure">@foreach ($disclosures as $disclosure)<option @selected(old('disclosure', $draft['disclosure']) === $disclosure)>{{ $disclosure }}</option>@endforeach</select>
            </label>
        </div>
        <div class="row">
            <label>公表日 <small>YYYY/MM/DD</small><input type="text" name="date" value="{{ old('date', $draft['date']) }}" required></label>
            <label>報の表示 <small>例：第2報（なければ空欄）</small><input type="text" name="report" value="{{ old('report', $draft['report']) }}"></label>
        </div>
        <label>一行説明<input type="text" name="brief" value="{{ old('brief', $draft['brief']) }}" required></label>
        <div class="row">
            <label>影響規模（詳細）<input type="text" name="quantity" value="{{ old('quantity', $draft['quantity']) }}" required></label>
            <label>影響規模（一覧用）<input type="text" name="scale" value="{{ old('scale', $draft['scale']) }}" required></label>
        </div>
        <label>対象情報<input type="text" name="data_type" value="{{ old('data_type', $draft['data_type']) }}" required></label>
        <label>資料時点の状況<input type="text" name="status" value="{{ old('status', $draft['status']) }}" required></label>
        <label>概要<textarea name="summary" required>{{ old('summary', $draft['summary']) }}</textarea></label>
        <label>対象者<textarea name="target" required>{{ old('target', $draft['target']) }}</textarea></label>
        <label>利用者がとるべき行動<textarea name="action" required>{{ old('action', $draft['action']) }}</textarea></label>
        <label>企業の対応<textarea name="response" required>{{ old('response', $draft['response']) }}</textarea></label>
        <label>公式発表 URL <small>https のみ</small><input type="text" name="source" value="{{ old('source', $draft['source']) }}" required></label>
        <label>問い合わせ先 URL <small>https のみ</small><input type="text" name="contact" value="{{ old('contact', $draft['contact']) }}" required></label>
        <label>パスワード（再入力） <small>承認操作の確認</small><input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit" class="primary">この内容で承認</button>
    </form>
</main>
</body>
</html>
