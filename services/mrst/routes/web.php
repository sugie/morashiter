<?php

use App\Http\Controllers\Admin\ArticlesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IncidentApprovalController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\IncidentCorrectionController;
use App\Http\Controllers\Admin\IncidentReviewController;
use App\Http\Controllers\Admin\ManualOfficialAnnouncementController;
use App\Http\Controllers\Admin\OfficialAnnouncementSearchController;
use App\Http\Controllers\Admin\OfficialDomainResearchController;
use App\Http\Controllers\Admin\OfficialLinkReviewController;
use App\Http\Controllers\Admin\PublicationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureAdminIpAllowed;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware(EnsureAdminIpAllowed::class)->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');

    Route::middleware(EnsureActiveAdmin::class)->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/articles', [ArticlesController::class, 'index'])->name('articles.index');
        Route::get('/reviews', [IncidentReviewController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{review}/approve', [IncidentApprovalController::class, 'create'])->name('reviews.approve');
        Route::post('/reviews/{review}/approve', [IncidentApprovalController::class, 'store'])->name('reviews.approve.store');
        Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::post('/incidents/{incident}/withdraw', [IncidentController::class, 'withdraw'])->name('incidents.withdraw');
        Route::get('/incidents/{incident}/correct', [IncidentCorrectionController::class, 'create'])->name('incidents.correct');
        Route::post('/incidents/{incident}/correct', [IncidentCorrectionController::class, 'store'])->name('incidents.correct.store');
        Route::get('/publications', [PublicationController::class, 'index'])->name('publications.index');
        Route::post('/publications', [PublicationController::class, 'publish'])->name('publications.publish');
        Route::post('/reviews/{review}/reject', [IncidentReviewController::class, 'reject'])->name('reviews.reject');
        Route::get('/link-candidates', [OfficialLinkReviewController::class, 'index'])->name('link-candidates.index');
        Route::get('/official-announcements', [ManualOfficialAnnouncementController::class, 'index'])->name('official-announcements.index');
        Route::post('/official-announcements', [ManualOfficialAnnouncementController::class, 'store'])->middleware('throttle:20,1')->name('official-announcements.store');
        Route::post('/official-announcements/{candidate}/retry', [ManualOfficialAnnouncementController::class, 'retry'])->middleware('throttle:20,1')->name('official-announcements.retry');
        Route::post('/official-announcements/{candidate}/search', [OfficialAnnouncementSearchController::class, 'search'])->middleware('throttle:20,1')->name('official-announcements.search');
        Route::post('/official-announcement-searches/{search}/dismiss', [OfficialAnnouncementSearchController::class, 'dismiss'])->name('official-announcement-searches.dismiss');
        Route::post('/link-candidates/reject-host', [OfficialLinkReviewController::class, 'rejectHost'])->name('link-candidates.reject-host');
        Route::post('/domain-research/request', [OfficialDomainResearchController::class, 'request'])->middleware('throttle:20,1')->name('domain-research.request');
        Route::post('/domain-research/decision', [OfficialDomainResearchController::class, 'decide'])->name('domain-research.decision');
        Route::post('/official-domains', [OfficialLinkReviewController::class, 'storeDomain'])->name('official-domains.store');
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    });
});
