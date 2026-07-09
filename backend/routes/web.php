<?php

use App\Http\Controllers\Admin\SchoolAdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\School\SchoolPortalController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$appDomain = config('app.domain', 'localhost');

/*
|--------------------------------------------------------------------------
| Platform (main domain only)
|--------------------------------------------------------------------------
| Constrained to the base domain so subdomain requests fall through to the
| school portal group below. One entry point: sign in, and if you are the
| super admin, create and manage schools.
*/

Route::domain($appDomain)->group(function () {
    Route::get('/', function () {
        if (! Auth::check()) {
            return redirect('/login');
        }

        return Auth::user()->is_super_admin
            ? redirect()->route('admin.dashboard')
            : redirect('/login');
    });

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [SchoolAdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/schools', [SchoolAdminController::class, 'store'])->name('schools.store');
        Route::get('/schools/{school}', [SchoolAdminController::class, 'show'])->name('schools.show');
    });
});

/*
|--------------------------------------------------------------------------
| Available on any host
|--------------------------------------------------------------------------
*/

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/auth/{provider}/redirect', [SsoController::class, 'redirect'])
    ->whereIn('provider', \App\Services\Auth\SsoManager::PROVIDERS)->name('sso.redirect');
Route::get('/auth/{provider}/callback', [SsoController::class, 'callback'])
    ->whereIn('provider', \App\Services\Auth\SsoManager::PROVIDERS)->name('sso.callback');

/*
|--------------------------------------------------------------------------
| School portal (subdomain: {school}.<APP_DOMAIN>)
|--------------------------------------------------------------------------
| Each school has a branded welcome/login page and, once signed in, a
| dashboard. Locally, {slug}.localhost:8000 resolves to 127.0.0.1 in
| Chromium browsers, so no hosts file edit is needed.
*/

Route::domain('{school}.'.$appDomain)->middleware('tenant')->group(function () {
    Route::get('/', [SchoolPortalController::class, 'welcome'])->name('school.welcome');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('school.login');
        Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('school.login.submit');
    });

    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', [SchoolPortalController::class, 'dashboard'])->name('school.dashboard');
        Route::post('/notifications/{id}/read', [SchoolPortalController::class, 'markNotificationRead'])->name('school.notifications.read');
        Route::get('/settings/branding', [SchoolPortalController::class, 'editBranding'])->name('school.branding.edit');
        Route::post('/settings/branding', [SchoolPortalController::class, 'updateBranding'])->name('school.branding.update');
    });
});
