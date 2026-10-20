<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentReview;
use App\Models\IncidentRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A human turns an AI draft from an official announcement into an approved incident revision.
 * The admin edits every published field and re-enters the password before approving.
 */
class IncidentApprovalController extends Controller
{
    public const TYPES = ['不正アクセス', 'ランサムウェア攻撃', 'クラウド設定不備', '誤送信・紛失', 'その他'];

    public const DISCLOSURES = ['漏えい確認', '漏えいの可能性', '区分確認中'];

    public function create(IncidentReview $review): View
    {
        $this->ensureApprovable($review);
        $review->load('officialDocument');

        return view('admin.reviews.approve', [
            'review' => $review,
            'draft' => $this->draftFrom($review),
            'types' => self::TYPES,
            'disclosures' => self::DISCLOSURES,
            'incidents' => Incident::query()->whereNull('withdrawn_at')->with('currentRevision')->orderBy('display_code')->get(),
        ]);
    }

    public function store(Request $request, IncidentReview $review): RedirectResponse
    {
        $this->ensureApprovable($review);
        $validated = $request->validate([
            'incident_id' => ['nullable', 'integer', Rule::exists('incidents', 'id')->whereNull('withdrawn_at')],
            'name' => ['required', 'string', 'max:100'],
            'company' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(self::TYPES)],
            'disclosure' => ['required', Rule::in(self::DISCLOSURES)],
            'report' => ['nullable', 'string', 'max:30'],
            'brief' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'string', 'max:100'],
            'scale' => ['required', 'string', 'max:100'],
            'data_type' => ['required', 'string', 'max:300'],
            'date' => ['required', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'status' => ['required', 'string', 'max:100'],
            'summary' => ['required', 'string', 'max:1000'],
            'target' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'string', 'max:1000'],
            'response' => ['required', 'string', 'max:1000'],
            'source' => ['required', 'url:https', 'max:2000'],
            'contact' => ['required', 'url:https', 'max:2000'],
            'password' => ['required', 'current_password:admin'],
        ]);

        $incident = DB::transaction(function () use ($validated, $review, $request): Incident {
            $locked = IncidentReview::query()->whereKey($review->id)->lockForUpdate()->first();
            abort_unless($locked->status === 'pending_review', 409, 'This draft was already decided.');

            $incident = isset($validated['incident_id'])
                ? Incident::query()->whereKey($validated['incident_id'])->lockForUpdate()->first()
                : Incident::query()->create(['display_code' => Incident::nextDisplayCode(), 'origin' => 'review']);
            $next = (int) IncidentRevision::query()->where('incident_id', $incident->id)->max('revision_number') + 1;

            $fields = ['report' => ($validated['report'] ?? '') === '' ? null : $validated['report']];
            foreach (IncidentRevision::PUBLIC_FIELDS as $siteKey => $column) {
                if ($column !== 'report') {
                    $fields[$column] = trim((string) $validated[$siteKey === 'dataType' ? 'data_type' : $siteKey]);
                }
            }
            IncidentRevision::query()->create($fields + [
                'incident_id' => $incident->id,
                'revision_number' => $next,
                'status' => 'approved',
                'approval_method' => 'manual',
                'approved_by_admin_id' => $request->user('admin')->id,
                'approved_at' => now(),
                'incident_review_id' => $review->id,
            ]);
            $locked->update(['status' => 'approved', 'reviewed_by_admin_id' => $request->user('admin')->id, 'reviewed_at' => now()]);

            return $incident;
        });

        return redirect()->route('admin.reviews.index')->with('status', "{$incident->display_code} を承認しました（公開は msgn の公開実行で反映されます）。");
    }

    private function ensureApprovable(IncidentReview $review): void
    {
        abort_unless($review->status === 'pending_review' && $review->analyzer !== 'mock', 409, 'This draft cannot be approved.');
    }

    /**
     * @return array<string, string>
     */
    private function draftFrom(IncidentReview $review): array
    {
        $analysis = $review->analysis ?? [];
        $organization = (string) ($analysis['organization'] ?? '');
        $text = ($analysis['incident_title'] ?? '').' '.($analysis['summary'] ?? '');
        $disclosedAt = (string) ($analysis['disclosed_at'] ?? '');
        $url = $review->officialDocument->url;

        return [
            'name' => $organization,
            'company' => $organization,
            'type' => match (true) {
                str_contains($text, 'ランサムウェア') => 'ランサムウェア攻撃',
                str_contains($text, '設定') => 'クラウド設定不備',
                str_contains($text, '誤送') || str_contains($text, '紛失') => '誤送信・紛失',
                default => '不正アクセス',
            },
            'disclosure' => str_contains($text, '可能性') ? '漏えいの可能性' : '漏えい確認',
            'report' => '',
            'brief' => (string) ($analysis['incident_title'] ?? ''),
            'quantity' => (string) ($analysis['affected_count_text'] ?? ''),
            'scale' => (string) ($analysis['affected_count_text'] ?? ''),
            'data_type' => implode('・', $analysis['data_types'] ?? []),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $disclosedAt) ? str_replace('-', '/', $disclosedAt) : '',
            'status' => mb_substr((string) ($analysis['response_status'] ?? ''), 0, 100),
            'summary' => (string) ($analysis['summary'] ?? ''),
            'target' => '公表資料に記載された対象者。対象かどうかは、会社からの個別案内または公式窓口で確認してください。',
            'action' => '会社を装うメール・SMS・電話に注意し、身に覚えのないリンクや添付を開かず、公式窓口から確認してください。',
            'response' => (string) ($analysis['response_status'] ?? ''),
            'source' => $url,
            'contact' => $url,
        ];
    }
}
