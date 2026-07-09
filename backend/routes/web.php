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
        Route::post('/schools/{school}/suspend', [SchoolAdminController::class, 'suspend'])->name('schools.suspend');
        Route::post('/schools/{school}/archive', [SchoolAdminController::class, 'archive'])->name('schools.archive');
        Route::post('/schools/{school}/activate', [SchoolAdminController::class, 'activate'])->name('schools.activate');
        Route::delete('/schools/{school}', [SchoolAdminController::class, 'destroy'])->name('schools.destroy');
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

    Route::middleware(['auth', 'school_member'])->group(function () {
        Route::get('/dashboard', [SchoolPortalController::class, 'dashboard'])->name('school.dashboard');

        // Students
        Route::get('/students', [\App\Http\Controllers\School\StudentController::class, 'index'])->name('school.students');
        Route::get('/students/import', [\App\Http\Controllers\School\StudentController::class, 'importForm'])->name('school.students.import');
        Route::post('/students/import', [\App\Http\Controllers\School\StudentController::class, 'import'])->name('school.students.import.run');
        Route::get('/students/template.csv', [\App\Http\Controllers\School\StudentController::class, 'template'])->name('school.students.template');
        Route::post('/notifications/{id}/read', [SchoolPortalController::class, 'markNotificationRead'])->name('school.notifications.read');
        Route::get('/settings/branding', [SchoolPortalController::class, 'editBranding'])->name('school.branding.edit');
        Route::post('/settings/branding', [SchoolPortalController::class, 'updateBranding'])->name('school.branding.update');
        Route::get('/settings/dashboard', [SchoolPortalController::class, 'editDashboard'])->name('school.dashboard.edit');
        Route::post('/settings/dashboard', [SchoolPortalController::class, 'updateDashboard'])->name('school.dashboard.update');

        // Academics: sections, classes, and per-class subject offerings.
        Route::prefix('academics')->name('school.academics.')->group(function () {
            $academics = \App\Http\Controllers\School\AcademicsController::class;
            Route::get('/', [$academics, 'index'])->name('index');
            Route::post('/sections', [$academics, 'storeSection'])->name('sections.store');
            Route::post('/classes', [$academics, 'storeClass'])->name('classes.store');
            Route::get('/classes/{classId}/subjects', [$academics, 'subjects'])->name('subjects');
            Route::post('/classes/{classId}/subjects/apply-defaults', [$academics, 'applyDefaults'])->name('subjects.apply-defaults');
            Route::post('/classes/{classId}/subjects/attach', [$academics, 'attachSubject'])->name('subjects.attach');
            Route::post('/classes/{classId}/subjects/new', [$academics, 'newSubject'])->name('subjects.new');
            Route::post('/classes/{classId}/subjects/{subjectId}', [$academics, 'updateSubject'])->name('subjects.update');
            Route::delete('/classes/{classId}/subjects/{subjectId}', [$academics, 'detachSubject'])->name('subjects.detach');
        });

        // Campuses.
        Route::get('/campuses', [\App\Http\Controllers\School\CampusController::class, 'index'])->name('school.campuses');
        Route::post('/campuses', [\App\Http\Controllers\School\CampusController::class, 'store'])->name('school.campuses.store');
        Route::delete('/campuses/{campusId}', [\App\Http\Controllers\School\CampusController::class, 'destroy'])->name('school.campuses.destroy');

        // Report cards: settings, preview, and the real per student render.
        Route::prefix('report-cards')->name('school.report-cards.')->group(function () {
            $reportCards = \App\Http\Controllers\School\ReportCardController::class;
            Route::get('/settings', [$reportCards, 'edit'])->name('edit');
            Route::post('/settings', [$reportCards, 'update'])->name('update');
            Route::get('/preview/{template?}', [$reportCards, 'preview'])->name('preview');
            Route::get('/{studentId}/{termId}', [$reportCards, 'show'])->name('show');
        });
    });
});
