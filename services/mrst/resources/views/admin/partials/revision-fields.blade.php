{{-- Published incident fields; expects $draft, $types and $disclosures. --}}
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
