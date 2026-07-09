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

    // Public "Start a Trial" lead form (user request 2026-07-09): no
    // authentication needed, the marketing site links straight here.
    Route::get('/trial', [\App\Http\Controllers\TrialRequestController::class, 'create'])->name('trial.create');
    Route::post('/trial', [\App\Http\Controllers\TrialRequestController::class, 'store'])->name('trial.store');
    Route::get('/trial/thanks', [\App\Http\Controllers\TrialRequestController::class, 'thanks'])->name('trial.thanks');

    Route::middleware(['auth', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PlatformDashboardController::class, 'index'])->name('dashboard');
        Route::get('/trial-requests', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'index'])->name('trial-requests.index');
        Route::post('/trial-requests/{trialRequest}/approve', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'approve'])->name('trial-requests.approve');
        Route::post('/trial-requests/{trialRequest}/reject', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'reject'])->name('trial-requests.reject');
        Route::get('/schools', [SchoolAdminController::class, 'schools'])->name('schools.index');
        Route::post('/schools', [SchoolAdminController::class, 'store'])->name('schools.store');
        Route::get('/schools/archive', [SchoolAdminController::class, 'trashed'])->name('schools.archive-list');
        Route::post('/schools/{schoolId}/restore', [SchoolAdminController::class, 'restore'])->name('schools.restore');
        Route::get('/schools/{school}', [SchoolAdminController::class, 'show'])->name('schools.show');
        Route::post('/schools/{school}/clone', [SchoolAdminController::class, 'cloneSchool'])->name('schools.clone');
        Route::post('/schools/{school}/admins', [SchoolAdminController::class, 'storeAdmin'])->name('schools.admins.store');
        Route::post('/schools/{school}/admins/{user}/reset-password', [SchoolAdminController::class, 'resetAdminPassword'])->name('schools.admins.reset-password');
        Route::post('/schools/{school}/admins/{user}/toggle-access', [SchoolAdminController::class, 'toggleAdminAccess'])->name('schools.admins.toggle-access');
        Route::post('/schools/{school}/admins/{user}/impersonate', [SchoolAdminController::class, 'impersonate'])->name('schools.admins.impersonate');
        Route::post('/schools/{school}/suspend', [SchoolAdminController::class, 'suspend'])->name('schools.suspend');
        Route::post('/schools/{school}/archive', [SchoolAdminController::class, 'archive'])->name('schools.archive');
        Route::post('/schools/{school}/activate', [SchoolAdminController::class, 'activate'])->name('schools.activate');
        Route::delete('/schools/{school}', [SchoolAdminController::class, 'destroy'])->name('schools.destroy');

        Route::post('/schools/{school}/backups', [\App\Http\Controllers\Admin\BackupAdminController::class, 'store'])->name('schools.backups.store');
        Route::get('/schools/{school}/backups/{backupId}/download', [\App\Http\Controllers\Admin\BackupAdminController::class, 'download'])->name('schools.backups.download');
        Route::post('/schools/{school}/backups/{backupId}/restore', [\App\Http\Controllers\Admin\BackupAdminController::class, 'restore'])->name('schools.backups.restore');

        Route::prefix('settings/mail')->name('settings.mail.')->group(function () {
            $mailSettings = \App\Http\Controllers\Admin\MailSettingsController::class;
            Route::get('/', [$mailSettings, 'edit'])->name('edit');
            Route::post('/', [$mailSettings, 'update'])->name('update');
            Route::post('/test', [$mailSettings, 'sendTest'])->name('test');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Available on any host
|--------------------------------------------------------------------------
*/

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
Route::post('/session/keep-alive', [\App\Http\Controllers\SessionController::class, 'keepAlive'])
    ->middleware('auth')->name('session.keep-alive');
Route::post('/session/end-impersonation', [SchoolAdminController::class, 'endImpersonation'])
    ->middleware('auth')->name('session.end-impersonation');

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

        // Backup and restore.
        Route::prefix('backups')->name('school.backups.')->group(function () {
            $backups = \App\Http\Controllers\School\BackupController::class;
            Route::get('/', [$backups, 'index'])->name('index');
            Route::post('/', [$backups, 'store'])->name('store');
            Route::post('/schedule', [$backups, 'updateSchedule'])->name('schedule');
            Route::get('/{backupId}/download', [$backups, 'download'])->name('download');
            Route::post('/{backupId}/restore', [$backups, 'restore'])->name('restore');
        });

        // Mail settings (optional per-school SMTP override).
        Route::prefix('settings/mail')->name('school.settings.mail.')->group(function () {
            $mailSettings = \App\Http\Controllers\School\MailSettingsController::class;
            Route::get('/', [$mailSettings, 'edit'])->name('edit');
            Route::post('/', [$mailSettings, 'update'])->name('update');
            Route::post('/test', [$mailSettings, 'sendTest'])->name('test');
        });
    });
});
