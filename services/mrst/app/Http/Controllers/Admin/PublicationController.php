<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Msgn\PublishSite;
use App\Models\SiteRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicationController extends Controller
{
    public function index(): View
    {
        return view('admin.publications.index', [
            'enabled' => (bool) config('msgn.enabled', false),
            'publicUrl' => config('msgn.public_url'),
            'releases' => SiteRelease::query()->with('triggeredBy')->latest('id')->limit(30)->get(),
        ]);
    }

    /**
     * Initial or emergency manual publication; routine publication is handled by the scheduler.
     */
    public function publish(Request $request): RedirectResponse
    {
        abort_unless(config('msgn.enabled', false), 409, 'Publishing is disabled (MSGN_ENABLED=false).');
        PublishSite::dispatch($request->user('admin')->id);

        return redirect()->route('admin.publications.index')->with('status', '公開を開始しました。数分後にこの画面を再読み込みして結果を確認してください。');
    }
}
