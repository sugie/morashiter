<?php

use App\Http\Controllers\Admin\ArticlesController;
use App\Http\Controllers\Admin\OfficialLinkReviewController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureAdminIpAllowed;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware(EnsureAdminIpAllowed::class)->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');

    Route::middleware(EnsureActiveAdmin::class)->group(function (): void {
        Route::get('/', fn () => view('admin.dashboard'))->name('dashboard');
        Route::get('/articles', [ArticlesController::class, 'index'])->name('articles.index');
        Route::get('/link-candidates', [OfficialLinkReviewController::class, 'index'])->name('link-candidates.index');
        Route::post('/link-candidates/reject-host', [OfficialLinkReviewController::class, 'rejectHost'])->name('link-candidates.reject-host');
        Route::post('/official-domains', [OfficialLinkReviewController::class, 'storeDomain'])->name('official-domains.store');
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    });
});
