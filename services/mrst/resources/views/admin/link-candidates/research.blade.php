@if ($research)
    <section aria-label="{{ $group->host }} のAI調査結果">
        <p><strong>AI調査：</strong>{{ ['queued' => '調査待ち', 'running' => '調査中', 'completed' => '調査完了', 'failed' => '調査失敗', 'superseded' => '人の判断を優先'][$research->status] ?? $research->status }}</p>
        <p><strong>{{ $research->recommendation === 'recommend' ? '登録推奨' : '保留' }}</strong>（人の確認が必要）</p>
        <p>組織名：{{ $research->organization_name ?? '未確認' }}</p>
        <p>理由：{{ $research->reason }}</p>
        <p>取得日時：{{ $research->completed_at?->format('Y-m-d H:i') ?? $research->started_at?->format('Y-m-d H:i') ?? '未取得' }}</p>
        <p>最終取得URL：{{ $research->final_url ?? '未取得' }}</p>
        @if ($research->redirect_url)<p>転送先（未取得）：{{ $research->redirect_url }}</p>@endif
        <ul>
            @foreach ($research->evidence ?? [] as $evidence)
                <li><a href="{{ $evidence['url'] }}" target="_blank" rel="noopener noreferrer">根拠：{{ $evidence['url'] }}</a><blockquote>{{ $evidence['quote'] }}</blockquote></li>
            @endforeach
        </ul>
        @if ($decision)
            <p>前回の人の判断：{{ $decision->decision === 'hold' ? '保留' : '登録' }} / {{ $decision->reason }}（{{ $decision->decided_at }}）</p>
        @endif
        <form method="post" action="{{ route('admin.domain-research.decision') }}">
            @csrf
            <input type="hidden" name="host" value="{{ $group->host }}">
            <input type="hidden" name="investigation_id" value="{{ $research->id }}">
            <p><label>確定する組織名 <input type="text" name="company_name" value="{{ $research->organization_name }}" maxlength="255"></label></p>
            <p><label>既存企業との照合 <select name="company_id"><option value="">上記の名称で照合・新規登録</option>@foreach ($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}（ID {{ $company->id }}）</option>@endforeach</select></label></p>
            <p>既存企業を選ぶ場合は、その登録名と組織名欄を一致させてください。</p>
            <p><label><input type="checkbox" name="evidence_checked" value="1"> 1. 根拠ページを開き、引用の存在を確認した</label></p>
            <p><label><input type="checkbox" name="ownership_checked" value="1"> 2. 組織自身のサイトであることを確認した</label></p>
            <p><label><input type="checkbox" name="name_checked" value="1"> 3. 登録名と照合し、必要な修正を行った</label></p>
            <p><label><input type="checkbox" name="batch_checked" value="1"> 4. 登録すると同hostの確認待ち候補 {{ $group->candidate_count }} 件を承認することを確認した</label></p>
            <p><label>5. 判断理由（必須） <textarea name="reason" required maxlength="2000"></textarea></label></p>
            @if ($research->status === 'completed' && $research->recommendation === 'recommend')
                <button type="submit" name="decision" value="register">確認結果を保存して登録</button>
            @endif
            <button type="submit" name="decision" value="hold" class="secondary">理由を保存して保留</button>
        </form>
    </section>
@endif
@if (! $research || ! config('domain_research.enabled'))
    <form class="inline" method="post" action="{{ route('admin.official-domains.store') }}">
        @csrf
        <input type="hidden" name="host" value="{{ $group->host }}">
        <input type="text" name="company_name" placeholder="企業名" required aria-label="{{ $group->host }} の企業名">
        <button type="submit">公式として登録</button>
    </form>
@endif
@if (config('domain_research.enabled'))
    <form class="inline" method="post" action="{{ route('admin.domain-research.request') }}">
        @csrf
        <input type="hidden" name="host" value="{{ $group->host }}">
        <button type="submit" class="secondary">{{ $research ? '再調査' : 'AIで調査' }}</button>
    </form>
@endif

