<?php

use App\Http\Controllers\Admin\ArticlesController;
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
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    });
});
