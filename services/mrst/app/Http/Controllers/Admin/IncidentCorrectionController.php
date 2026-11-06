<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Fixes a published incident by adding a new approved revision. Approved revisions are never edited,
 * so the earlier one stays as history; msgn publishes the latest.
 */
class IncidentCorrectionController extends Controller
{
    public function create(Incident $incident): View
    {
        $this->ensureCorrectable($incident);
        $revision = $incident->currentRevision;

        return view('admin.incidents.correct', [
            'incident' => $incident,
            'revision' => $revision,
            'draft' => $this->draftFrom($revision),
            'types' => IncidentApprovalController::TYPES,
            'disclosures' => IncidentApprovalController::DISCLOSURES,
        ]);
    }

    public function store(Request $request, Incident $incident): RedirectResponse
    {
        $this->ensureCorrectable($incident);
        // Corrections are not re-confirmed with the password; approvals and withdrawals still are.
        $validated = $request->validate(['base_revision' => ['required', 'integer']] + Arr::except(IncidentApprovalController::revisionRules(), 'password'));

        $revision = DB::transaction(function () use ($validated, $incident, $request): IncidentRevision {
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->first();
            abort_if($locked->withdrawn_at !== null, 409, 'This incident is withdrawn.');
            // A form opened before another correction or follow-up approval would silently undo it.
            if ((int) $validated['base_revision'] !== $locked->currentRevision?->revision_number) {
                throw ValidationException::withMessages(['base_revision' => 'このフォームを開いた後に新しい版が作成されています。開き直して最新の内容から訂正してください。']);
            }
            $next = (int) IncidentRevision::query()->where('incident_id', $locked->id)->max('revision_number') + 1;

            return IncidentRevision::query()->create(IncidentApprovalController::revisionFields($validated) + [
                'incident_id' => $locked->id,
                'revision_number' => $next,
                'status' => 'approved',
                'approval_method' => 'correction',
                'approved_by_admin_id' => $request->user('admin')->id,
                'approved_at' => now(),
            ]);
        });

        return redirect()->route('admin.incidents.index')
            ->with('status', "{$incident->display_code} の第{$revision->revision_number}版（訂正）を作成しました（公開は msgn の公開実行で反映されます）。");
    }

    private function ensureCorrectable(Incident $incident): void
    {
        abort_if($incident->withdrawn_at !== null, 409, 'This incident is withdrawn.');
        abort_if($incident->currentRevision === null, 409, 'This incident has no approved revision.');
    }

    /**
     * @return array<string, string>
     */
    private function draftFrom(IncidentRevision $revision): array
    {
        $draft = [];
        foreach (IncidentRevision::PUBLIC_FIELDS as $siteKey => $column) {
            $draft[$siteKey === 'dataType' ? 'data_type' : $siteKey] = (string) $revision->{$column};
        }

        return $draft;
    }
}
