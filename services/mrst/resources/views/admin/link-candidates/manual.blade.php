<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公式発表URLの手動追加 | Morashiter 管理画面</title>
    <style>
        :root { font-family: system-ui, sans-serif; color: #17283b; background: #f5f7f8; }
        body { margin: 0; }
        header { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1rem 2rem; background: #164c66; color: white; }
        header a { color: white; margin-right: 1rem; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
        section, details { background: white; border: 1px solid #d7dfe7; border-radius: .4rem; padding: 1rem; margin-bottom: 1.5rem; }
        label { display: block; margin: .8rem 0; }
        select, input[type=url], input[type=text] { box-sizing: border-box; width: 100%; padding: .6rem; font: inherit; border: 1px solid #b9c6d2; border-radius: .25rem; }
        button { padding: .5rem .8rem; background: #164c66; color: white; border: 1px solid #164c66; border-radius: .25rem; font: inherit; cursor: pointer; }
        header button { border-color: white; }
        .note, .status, .errors { padding: .8rem 1rem; border-left: 4px solid #c38b1b; background: #fff8e5; }
        .status { border-color: #2f7d4f; background: #e8f3ec; }
        .errors { border-color: #b3261e; background: #fdecec; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { padding: .6rem; border-bottom: 1px solid #d7dfe7; text-align: left; vertical-align: top; }
        th { background: #eef3f6; }
        a { color: #0b5f87; overflow-wrap: anywhere; }
        small { display: block; color: #596877; margin-top: .3rem; overflow-wrap: anywhere; }
        .pager { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        @media (max-width: 640px) {
            header { padding: 1rem; }
            header a { display: inline-block; margin-top: .5rem; }
            table, tbody, tr, td { display: block; box-sizing: border-box; width: 100%; }
            thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
            tr { border: 1px solid #d7dfe7; margin-bottom: 1rem; }
            td[data-label]::before { content: attr(data-label); display: block; font-weight: bold; }
        }
    </style>
</head>
<body>
<header>
    <div><strong>Morashiter 管理画面</strong>
        <a href="{{ route('admin.dashboard') }}">ダッシュボード</a>
        <a href="{{ route('admin.link-candidates.index') }}">公式発表リンク</a>
        <a href="{{ route('admin.reviews.index') }}">審査キュー</a>
    </div>
    <form method="post" action="{{ route('admin.logout') }}">@csrf <button type="submit">ログアウト</button></form>
</header>
<main>
    <h1>公式発表URLの手動追加</h1>
    <p>企業・行政の個別発表ページやPDFを追加します。取得後にAIが下書きを作り、審査キューに送ります。承認・公開はそれぞれ管理画面で行ってください。</p>
    @if (session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="errors" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if ($fetchClosed)<p class="note">公式発表の取得が停止中です。追加したURLは取得待ちとして保存し、有効化後の定期処理で取得します。</p>@endif
    @if ($aiProvider === 'mock')<p class="note">現在は mock 解析です。実AIによる下書きではないため承認できません。AI有効化後の定期解析で作り直されます。</p>@endif
    <section>
        <h2>公式発表を追加</h2>
        <form method="post" action="{{ route('admin.official-announcements.store') }}">
            @csrf
            <label for="official-domain">企業・組織と登録済み公式ドメイン</label>
            <select id="official-domain" name="official_domain_id" required>
                <option value="">選択してください</option>
                @foreach ($domains as $domain)<option value="{{ $domain->id }}" @selected((string) old('official_domain_id') === (string) $domain->id)>{{ $domain->company->name }} — {{ $domain->host }}</option>@endforeach
            </select>
            <label for="notice-url">公式発表URL（HTTPS）</label>
            <input id="notice-url" name="url" type="url" maxlength="2048" value="{{ old('url') }}" placeholder="https://example.jp/news/notice.html" required aria-describedby="url-help">
            <small id="url-help">トップページではなく、発表本文のあるページまたはPDFを指定してください。URLのホストは選択した公式ドメインと一致する必要があります。</small>
            <label><input type="checkbox" name="source_checked" value="1" @checked(old('source_checked')) required> 発表本文を開き、選択した企業・組織自身が公表した資料であることを確認しました。</label>
            <button type="submit">追加して取得・解析へ進める</button>
        </form>
    </section>
    <details>
        <summary>選択肢に公式ドメインがない場合</summary>
        <p>企業・組織自身のサイトであることを確認して登録してください。既存企業と同じ組織の場合は登録済みの企業名を正確に入力してください。登録後は「公式発表URLを手動追加」から戻れます。</p>
        <p>AI調査中のホストは、<a href="{{ route('admin.link-candidates.index') }}">公式発表リンクの確認</a>で調査結果を確認して登録してください。</p>
        <form method="post" action="{{ route('admin.official-domains.store') }}">
            @csrf
            <label>公式ホスト名（URLではなくホストのみ）<input type="text" name="host" placeholder="www.example.jp" required></label>
            <label>企業・組織名<input type="text" name="company_name" maxlength="255" required></label>
            <button type="submit">確認した公式ドメインを登録</button>
        </form>
    </details>
    <section>
        <h2>手動追加した公式発表</h2>
        <p>画面を再読み込みすると進捗を確認できます。AIの日次上限に達した場合は解析待ちになります。</p>
        <table><thead><tr><th>追加日時・URL</th><th>処理状況</th><th>操作</th></tr></thead><tbody>
        @forelse ($submissions as $submission)
            @php
                $document = ($documents[$submission->url_hash] ?? collect())->first();
                $review = $document?->review;
            @endphp
            <tr>
                <td data-label="追加日時・URL">#{{ $submission->id }} / {{ $submission->created_at?->format('Y-m-d H:i') }}<br><a href="{{ $submission->url }}" target="_blank" rel="noopener noreferrer">{{ $submission->url }}</a></td>
                <td data-label="処理状況">
                    @if ($review)
                        {{ match ($review->status) { 'pending_review' => '審査待ち', 'analysis_failed' => '解析失敗', 'approved' => '承認済み（公開状況は公開画面で確認）', 'rejected' => '却下済み', default => $review->status } }}
                        <small>{{ $review->analyzer }} @if ($review->error_code) / {{ $review->error_code }} @endif</small>
                        @if ($review->error_code === 'no_text')<small>本文を抽出できません。本文のある別の公式発表URLを追加してください。</small>@endif
                    @elseif ($document) 解析待ち
                    @else
                        {{ match ($submission->status) { 'accepted' => '取得待ち', 'running' => '取得中', 'failed' => '取得失敗', 'fetched' => '発表本文未取得', default => $submission->status } }}
                        @if ($submission->error_code)<small>{{ $submission->error_code }}</small>@endif
                    @endif
                </td>
                <td data-label="操作">
                    @if ($review?->status === 'pending_review' || $review?->status === 'analysis_failed')<a href="{{ route('admin.reviews.index') }}">審査キューを開く</a>@endif
                    @if ($submission->status === 'failed' || ($submission->status === 'fetched' && $submission->error_code === 'no_incident_link'))
                        <form method="post" action="{{ route('admin.official-announcements.retry', $submission) }}">@csrf <button type="submit">再取得</button></form>
                    @endif
                </td>
            </tr>
        @empty<tr><td colspan="3">まだ手動追加された公式発表はありません。</td></tr>@endforelse
        </tbody></table>
        @if ($submissions->hasPages())
            @php($submissions->appends(request()->only('unresolved_page')))
            <nav class="pager" aria-label="手動追加の履歴のページ切り替え">
                @if ($submissions->previousPageUrl())<a href="{{ $submissions->previousPageUrl() }}">前へ</a>@endif
                <span>{{ $submissions->currentPage() }} / {{ $submissions->lastPage() }} ページ</span>
                @if ($submissions->nextPageUrl())<a href="{{ $submissions->nextPageUrl() }}">次へ</a>@endif
            </nav>
        @endif
    </section>
    <section>
        <h2>公式発表を見つけられなかった候補</h2>
        <p>トップページから発表を自動発見できなかった候補です。ニュースの内容と対応する個別発表を確認し、上のフォームに追加してください。追加後も元の探索記録は残ります。</p>
        <table><thead><tr><th>候補</th><th>発見元ニュース</th></tr></thead><tbody>
        @forelse ($unresolved as $candidate)
            <tr><td data-label="候補">#{{ $candidate->id }} {{ $candidate->anchor_text }}<br><a href="{{ $candidate->url }}" target="_blank" rel="noopener noreferrer">{{ $candidate->url }}</a></td>
                <td data-label="発見元ニュース">@if ($candidate->sourceDocument)<a href="{{ $candidate->sourceDocument->canonical_url }}" target="_blank" rel="noopener noreferrer">{{ $candidate->sourceDocument->title }}</a>@else — @endif</td></tr>
        @empty<tr><td colspan="2">該当する候補はありません。</td></tr>@endforelse
        </tbody></table>
        @if ($unresolved->hasPages())
            @php($unresolved->appends(request()->only('page')))
            <nav class="pager" aria-label="未発見候補のページ切り替え">
                @if ($unresolved->previousPageUrl())<a href="{{ $unresolved->previousPageUrl() }}">前へ</a>@endif
                <span>{{ $unresolved->currentPage() }} / {{ $unresolved->lastPage() }} ページ</span>
                @if ($unresolved->nextPageUrl())<a href="{{ $unresolved->nextPageUrl() }}">次へ</a>@endif
            </nav>
        @endif
    </section>
</main>
</body>
</html>
