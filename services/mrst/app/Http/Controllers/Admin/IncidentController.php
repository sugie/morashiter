<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncidentController extends Controller
{
    public function index(): View
    {
        return view('admin.incidents.index', [
            'incidents' => Incident::query()->with('currentRevision')->orderBy('display_code')->get(),
        ]);
    }

    /**
     * Withdrawn incidents leave the list and are shown as a correction/withdrawal notice by msgn.
     */
    public function withdraw(Request $request, Incident $incident): RedirectResponse
    {
        abort_if($incident->withdrawn_at !== null, 409, 'This incident is already withdrawn.');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'password' => ['required', 'current_password:admin'],
        ]);

        $incident->update([
            'withdrawn_at' => now(),
            'withdrawal_reason' => trim($validated['reason']),
            'withdrawn_by_admin_id' => $request->user('admin')->id,
        ]);

        return redirect()->route('admin.incidents.index')->with('status', "{$incident->display_code} を撤回しました（公開は msgn の公開実行で反映されます）。");
    }
}
