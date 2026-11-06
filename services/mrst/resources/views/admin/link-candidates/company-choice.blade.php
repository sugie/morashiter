@php
    $aiName = trim((string) ($research?->organization_name ?? ''));
    $listed = collect($suggestions)->reject(fn (string $name): bool => $name === $aiName)->values();
    $other = \App\Http\Controllers\Admin\OfficialLinkReviewController::COMPANY_CHOICE_OTHER;
    $isOldInput = old('host') === $group->host && old('company_choice') !== null;
    // Only an AI-researched name is preselected; link labels are unverified, so the admin picks one on purpose.
    $selected = $isOldInput ? (string) old('company_choice') : ($aiName !== '' ? $aiName : ($listed->isEmpty() ? $other : ''));
@endphp
<label>企業・組織名
    <select name="company_choice" aria-label="{{ $group->host }} の企業名">
        @if ($aiName === '' && $listed->isNotEmpty())
            <option value="" @selected($selected === '')>選択してください</option>
        @endif
        @if ($aiName !== '')
            <optgroup label="AI調査">
                <option value="{{ $aiName }}" @selected($selected === $aiName)>{{ $aiName }}（AI調査）</option>
            </optgroup>
        @endif
        @if ($listed->isNotEmpty())
            <optgroup label="リンク表記・登録済み企業">
                @foreach ($listed as $name)
                    <option value="{{ $name }}" @selected($selected === $name)>{{ $name }}</option>
                @endforeach
            </optgroup>
        @endif
        <option value="{{ $other }}" @selected($selected === $other)>その他（下の欄に入力）</option>
    </select>
</label>
<label>その他の企業名 <input type="text" name="company_name_other" value="{{ $isOldInput ? old('company_name_other') : '' }}" maxlength="255" placeholder="「その他」を選んだときだけ入力"></label>
