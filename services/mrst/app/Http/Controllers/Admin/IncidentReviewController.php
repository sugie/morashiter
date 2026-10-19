<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncidentReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Human decision on AI drafts made from official announcements. Only approved drafts may be published.
 */
class IncidentReviewController extends Controller
{
    public function index(): View
    {
        return view('admin.reviews.index', [
            'reviews' => IncidentReview::query()
                ->with('officialDocument.officialDomain.company')
                ->whereIn('status', ['pending_review', 'analysis_failed'])
                ->orderByRaw("case status when 'pending_review' then 0 else 1 end")
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function approve(Request $request, IncidentReview $review): RedirectResponse
    {
        abort_unless($review->status === 'pending_review' && $review->analyzer !== 'mock', 409, 'This draft cannot be approved.');

        return $this->decide($request, $review, 'approved', '承認しました。');
    }

    public function reject(Request $request, IncidentReview $review): RedirectResponse
    {
        abort_unless(in_array($review->status, ['pending_review', 'analysis_failed'], true), 409, 'This review is already decided.');

        return $this->decide($request, $review, 'rejected', '却下しました。');
    }

    private function decide(Request $request, IncidentReview $review, string $status, string $message): RedirectResponse
    {
        $review->update([
            'status' => $status,
            'reviewed_by_admin_id' => $request->user('admin')->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('admin.reviews.index')->with('status', "#{$review->id} を{$message}");
    }
}
