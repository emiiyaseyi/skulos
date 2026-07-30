<?php

use App\Http\Controllers\Admin\SchoolAdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\GeoController;
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
        Route::get('/login/forgot', [LoginController::class, 'forgotPasswordForm'])->name('login.forgot');
        Route::post('/login/forgot', [LoginController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('login.forgot.submit');
        Route::get('/login/reset', [LoginController::class, 'resetViaTokenForm'])->name('login.reset');
        Route::post('/login/reset', [LoginController::class, 'resetViaToken'])->middleware('throttle:5,1')->name('login.reset.submit');
    });

    // Public "Start a Trial" lead form (user request 2026-07-09): no
    // authentication needed, the marketing site links straight here.
    Route::get('/trial', [\App\Http\Controllers\TrialRequestController::class, 'create'])->name('trial.create');
    Route::post('/trial', [\App\Http\Controllers\TrialRequestController::class, 'store'])->name('trial.store');
    Route::get('/trial/thanks', [\App\Http\Controllers\TrialRequestController::class, 'thanks'])->name('trial.thanks');

    // Self-hosted license check-in (user request 2026-07-18): called by a
    // school's own self-hosted deployment, never by a browser. No auth,
    // the license key itself is the credential; throttled since it is
    // unauthenticated.
    Route::post('/api/license/verify', [\App\Http\Controllers\LicenseVerifyController::class, 'verify'])
        ->middleware('throttle:30,1')
        ->name('license.verify');

    Route::middleware(['auth', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PlatformDashboardController::class, 'index'])->name('dashboard');
        Route::get('/trial-requests', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'index'])->name('trial-requests.index');
        Route::post('/trial-requests/{trialRequest}/approve', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'approve'])->name('trial-requests.approve');
        Route::post('/trial-requests/{trialRequest}/reject', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'reject'])->name('trial-requests.reject');
        Route::post('/trial-requests/bulk-reject', [\App\Http\Controllers\Admin\TrialRequestAdminController::class, 'bulkReject'])->name('trial-requests.bulk-reject');
        Route::get('/broadcast', [\App\Http\Controllers\Admin\BroadcastController::class, 'create'])->name('broadcast.create');
        Route::post('/broadcast', [\App\Http\Controllers\Admin\BroadcastController::class, 'send'])->name('broadcast.send');
        Route::get('/audit-log', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->name('audit-log.index');
        Route::post('/audit-log/verify', [\App\Http\Controllers\Admin\AuditLogController::class, 'verify'])->name('audit-log.verify');
        Route::get('/modules', [\App\Http\Controllers\Admin\ModuleAdminController::class, 'index'])->name('modules.index');
        Route::get('/backups', [\App\Http\Controllers\Admin\BackupAdminController::class, 'index'])->name('backups.index');
        Route::prefix('platform-backups')->name('platform-backups.')->group(function () {
            $platformBackups = \App\Http\Controllers\Admin\PlatformBackupController::class;
            Route::get('/', [$platformBackups, 'index'])->name('index');
            Route::post('/', [$platformBackups, 'store'])->name('store');
            Route::get('/{backupId}/download', [$platformBackups, 'download'])->name('download');
            Route::delete('/{backupId}', [$platformBackups, 'destroy'])->name('destroy');
        });
        Route::get('/team', [\App\Http\Controllers\Admin\PlatformTeamController::class, 'index'])->name('team.index');
        Route::post('/team', [\App\Http\Controllers\Admin\PlatformTeamController::class, 'store'])->name('team.store');
        Route::post('/team/{user}/revoke', [\App\Http\Controllers\Admin\PlatformTeamController::class, 'revoke'])->name('team.revoke');

        Route::prefix('marketing-pages')->name('marketing-pages.')->group(function () {
            $marketingPages = \App\Http\Controllers\Admin\MarketingPageController::class;
            Route::get('/', [$marketingPages, 'index'])->name('index');
            Route::get('/{page}', [$marketingPages, 'edit'])->name('edit');
            Route::post('/{page}', [$marketingPages, 'update'])->name('update');
        });
        Route::prefix('knowledge-base')->name('knowledge-base.')->group(function () {
            $kb = \App\Http\Controllers\Admin\KnowledgeBaseController::class;
            Route::get('/', [$kb, 'index'])->name('index');
            Route::post('/categories', [$kb, 'storeCategory'])->name('categories.store');
            Route::post('/categories/{categoryId}', [$kb, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{categoryId}', [$kb, 'destroyCategory'])->name('categories.destroy');
            Route::post('/articles', [$kb, 'storeArticle'])->name('articles.store');
            Route::get('/articles/{articleId}/edit', [$kb, 'editArticle'])->name('articles.edit');
            Route::post('/articles/{articleId}', [$kb, 'updateArticle'])->name('articles.update');
            Route::delete('/articles/{articleId}', [$kb, 'destroyArticle'])->name('articles.destroy');
        });
        Route::get('/schools', [SchoolAdminController::class, 'schools'])->name('schools.index');
        Route::post('/schools', [SchoolAdminController::class, 'store'])->name('schools.store');
        Route::get('/schools/archive', [SchoolAdminController::class, 'trashed'])->name('schools.archive-list');
        Route::post('/schools/{schoolId}/restore', [SchoolAdminController::class, 'restore'])->name('schools.restore');
        Route::post('/schools/archive/bulk-restore', [SchoolAdminController::class, 'bulkRestore'])->name('schools.archive.bulk-restore');
        Route::get('/schools/{school}', [SchoolAdminController::class, 'show'])->name('schools.show');
        Route::get('/schools/{school}/overview', [SchoolAdminController::class, 'overview'])->name('schools.overview');
        Route::post('/schools/{school}/billing-date', [SchoolAdminController::class, 'updateBillingDate'])->name('schools.billing-date');
        Route::post('/schools/{school}/clone', [SchoolAdminController::class, 'cloneSchool'])->name('schools.clone');
        Route::post('/schools/{school}/license/enable', [SchoolAdminController::class, 'enableSelfHosted'])->name('schools.license.enable');
        Route::post('/schools/{school}/license/regenerate', [SchoolAdminController::class, 'regenerateLicense'])->name('schools.license.regenerate');
        Route::post('/schools/{school}/license/revoke', [SchoolAdminController::class, 'revokeSelfHosted'])->name('schools.license.revoke');
        Route::post('/schools/{school}/admins', [SchoolAdminController::class, 'storeAdmin'])->name('schools.admins.store');
        Route::post('/schools/{school}/admins/bulk-action', [SchoolAdminController::class, 'bulkAdminAction'])->name('schools.admins.bulk-action');
        Route::post('/schools/{school}/admins/{user}/reset-password', [SchoolAdminController::class, 'resetAdminPassword'])->name('schools.admins.reset-password');
        Route::post('/schools/{school}/admins/{user}/toggle-access', [SchoolAdminController::class, 'toggleAdminAccess'])->name('schools.admins.toggle-access');
        Route::post('/schools/{school}/admins/{user}/impersonate', [SchoolAdminController::class, 'impersonate'])->name('schools.admins.impersonate');
        Route::post('/schools/bulk-action', [SchoolAdminController::class, 'bulkAction'])->name('schools.bulk-action');
        Route::post('/schools/{school}/suspend', [SchoolAdminController::class, 'suspend'])->name('schools.suspend');
        Route::post('/schools/{school}/archive', [SchoolAdminController::class, 'archive'])->name('schools.archive');
        Route::post('/schools/{school}/activate', [SchoolAdminController::class, 'activate'])->name('schools.activate');
        Route::delete('/schools/{school}', [SchoolAdminController::class, 'destroy'])->name('schools.destroy');

        Route::post('/schools/{school}/backups', [\App\Http\Controllers\Admin\BackupAdminController::class, 'store'])->name('schools.backups.store');
        Route::post('/schools/{school}/backups/schedule', [\App\Http\Controllers\Admin\BackupAdminController::class, 'updateSchedule'])->name('schools.backups.schedule');
        Route::get('/schools/{school}/backups/{backupId}/download', [\App\Http\Controllers\Admin\BackupAdminController::class, 'download'])->name('schools.backups.download');
        Route::post('/schools/{school}/backups/{backupId}/restore', [\App\Http\Controllers\Admin\BackupAdminController::class, 'restore'])->name('schools.backups.restore');

        Route::get('/settings', [\App\Http\Controllers\Admin\PlatformSettingsController::class, 'index'])->name('settings.index');

        Route::prefix('settings/mail')->name('settings.mail.')->group(function () {
            $mailSettings = \App\Http\Controllers\Admin\MailSettingsController::class;
            Route::get('/', [$mailSettings, 'edit'])->name('edit');
            Route::post('/', [$mailSettings, 'update'])->name('update');
            Route::post('/test', [$mailSettings, 'sendTest'])->name('test');
        });
        Route::prefix('settings/landing-pages')->name('settings.landing-pages.')->group(function () {
            $landingPages = \App\Http\Controllers\Admin\LandingPageSettingsController::class;
            Route::get('/', [$landingPages, 'edit'])->name('edit');
            Route::post('/', [$landingPages, 'update'])->name('update');
        });
        Route::prefix('settings/idle-timeout')->name('settings.idle-timeout.')->group(function () {
            $idleTimeout = \App\Http\Controllers\Admin\IdleTimeoutController::class;
            Route::get('/', [$idleTimeout, 'edit'])->name('edit');
            Route::post('/', [$idleTimeout, 'update'])->name('update');
        });
        Route::prefix('settings/retention')->name('settings.retention.')->group(function () {
            $retention = \App\Http\Controllers\Admin\RetentionController::class;
            Route::get('/', [$retention, 'edit'])->name('edit');
            Route::post('/', [$retention, 'update'])->name('update');
        });
        Route::prefix('settings/support-contact')->name('settings.support-contact.')->group(function () {
            $supportContact = \App\Http\Controllers\Admin\SupportContactController::class;
            Route::get('/', [$supportContact, 'edit'])->name('edit');
            Route::post('/', [$supportContact, 'update'])->name('update');
        });
        Route::prefix('settings/subscription-bank')->name('settings.subscription-bank.')->group(function () {
            $subscriptionBank = \App\Http\Controllers\Admin\SubscriptionController::class;
            Route::get('/', [$subscriptionBank, 'bankEdit'])->name('edit');
            Route::post('/', [$subscriptionBank, 'bankUpdate'])->name('update');
        });
        Route::prefix('settings/self-hosted-license')->name('settings.self-hosted-license.')->group(function () {
            $license = \App\Http\Controllers\Admin\LicenseSettingsController::class;
            Route::get('/', [$license, 'edit'])->name('edit');
            Route::post('/', [$license, 'update'])->name('update');
        });

        Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
            $subscriptions = \App\Http\Controllers\Admin\SubscriptionController::class;
            Route::get('/', [$subscriptions, 'index'])->name('index');
            Route::get('/{school}', [$subscriptions, 'show'])->name('show');
            Route::post('/{school}/remind', [$subscriptions, 'remind'])->name('remind');
            Route::post('/{school}/extend', [$subscriptions, 'extend'])->name('extend');
            Route::post('/{school}/renew', [$subscriptions, 'renew'])->name('renew');
            Route::post('/{school}/demand', [$subscriptions, 'demand'])->name('demand');
            Route::post('/{school}/reinstate', [$subscriptions, 'reinstate'])->name('reinstate');
            Route::post('/payments/bulk-action', [$subscriptions, 'bulkDecide'])->name('payments.bulk-action');
            Route::post('/payments/{payment}/verify', [$subscriptions, 'verify'])->name('payments.verify');
            Route::post('/payments/{payment}/reject', [$subscriptions, 'reject'])->name('payments.reject');
            Route::get('/payments/{payment}/evidence', [$subscriptions, 'evidence'])->name('payments.evidence');
        });

        Route::prefix('billing')->name('billing.')->group(function () {
            $billing = \App\Http\Controllers\Admin\BillingController::class;
            Route::get('/', [$billing, 'index'])->name('index');
            Route::post('/plans', [$billing, 'storePlan'])->name('plans.store');
            Route::post('/plans/{plan}', [$billing, 'updatePlan'])->name('plans.update');
            Route::post('/plans/{plan}/toggle-active', [$billing, 'togglePlanActive'])->name('plans.toggle-active');
            Route::post('/plans/{plan}/toggle-purchasable', [$billing, 'togglePlanPurchasable'])->name('plans.toggle-purchasable');
            Route::post('/schools/{school}/assign-plan', [$billing, 'assignPlan'])->name('schools.assign-plan');
            Route::post('/schools/{school}/toggle-feature', [$billing, 'toggleFeature'])->name('schools.toggle-feature');
            Route::post('/schools/{school}/add-ons', [$billing, 'grantAddOn'])->name('schools.add-ons.store');
            Route::delete('/add-ons/{addOn}', [$billing, 'revokeAddOn'])->name('add-ons.destroy');
            Route::post('/schools/{school}/waivers', [$billing, 'addWaiver'])->name('schools.waivers.store');
            Route::delete('/waivers/{waiver}', [$billing, 'removeWaiver'])->name('waivers.destroy');
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
Route::get('/session/resume-admin/{admin}', [SchoolAdminController::class, 'resumeAdmin'])
    ->middleware('signed')->name('session.resume-admin');

Route::get('/auth/{provider}/redirect', [SsoController::class, 'redirect'])
    ->whereIn('provider', \App\Services\Auth\SsoManager::PROVIDERS)->name('sso.redirect');
Route::get('/auth/{provider}/callback', [SsoController::class, 'callback'])
    ->whereIn('provider', \App\Services\Auth\SsoManager::PROVIDERS)->name('sso.callback');

/*
|--------------------------------------------------------------------------
| Geo reference lookups (country/state/LGA picker, any host)
|--------------------------------------------------------------------------
| Platform level reference data behind the reusable geo picker (user
| request 2026-07-11). No domain constraint on purpose: the picker is used
| from school subdomain pages (e.g. the student form), but this data has
| nothing to do with any one school, so it must not sit inside either
| domain-constrained group above.
*/

Route::prefix('geo')->name('geo.')->group(function () {
    Route::get('/countries', [GeoController::class, 'countries'])->name('countries');
    Route::get('/countries/{countryId}/states', [GeoController::class, 'states'])->name('states');
    Route::get('/states/{stateId}/lgas', [GeoController::class, 'lgas'])->name('lgas');
});

/*
|--------------------------------------------------------------------------
| Public plan catalog (marketing site CMS wiring, any host)
|--------------------------------------------------------------------------
| Same "reference data, no domain constraint, no auth" reasoning as geo
| above, except this one is also called cross origin from the separately
| hosted static site under /site — see config/cors.php.
*/

Route::get('/plans', [\App\Http\Controllers\PlanCatalogController::class, 'index'])->name('plans.index');

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
        Route::get('/password/forgot', [\App\Http\Controllers\School\ProfileController::class, 'forgotPasswordForm'])->name('school.password.forgot');
        Route::post('/password/forgot', [\App\Http\Controllers\School\ProfileController::class, 'requestPasswordReset'])->middleware('throttle:5,1')->name('school.password.forgot.submit');
        Route::post('/password/email-link', [\App\Http\Controllers\School\ProfileController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('school.password.email-link');
        Route::get('/password/reset', [\App\Http\Controllers\School\ProfileController::class, 'resetViaTokenForm'])->name('school.password.reset');
        Route::post('/password/reset', [\App\Http\Controllers\School\ProfileController::class, 'resetViaToken'])->middleware('throttle:5,1')->name('school.password.reset.submit');
    });

    // Admissions module, Phase 1a (user directive 2026-07-30): an
    // applicant's own sign-in, entirely separate from the 'web' guard
    // above (see App\Models\Tenant\Applicant's docblock for why). Public
    // application entry ("Seek Admission") and the applicant dashboard
    // itself land here in later Admissions sub-phases.
    Route::prefix('admissions')->name('school.admissions.')->group(function () {
        $applicantAuth = \App\Http\Controllers\Admissions\ApplicantAuthController::class;

        Route::middleware('guest:applicant')->group(function () use ($applicantAuth) {
            Route::get('/login', [$applicantAuth, 'show'])->name('login');
            Route::post('/login', [$applicantAuth, 'login'])->middleware('throttle:10,1')->name('login.submit');
        });

        // Not 'auth:applicant': that's Laravel's generic Authenticate
        // middleware, which on an unauthenticated request falls back to
        // bootstrap/app.php's global redirectGuestsTo closure (built for
        // the 'web' guard's school.login route, wrong destination for an
        // applicant). applicant_active (EnsureApplicantActive) already
        // covers "not authenticated" AND "authenticated but expired/
        // locked out/archived" in one place, with the correct redirect.
        Route::middleware('applicant_active')->group(function () use ($applicantAuth) {
            Route::post('/logout', [$applicantAuth, 'logout'])->name('logout');
            Route::get('/dashboard', [\App\Http\Controllers\Admissions\ApplicantDashboardController::class, 'index'])->name('dashboard');
        });

        // Public "Seek Admission" entry point, Phase 1c: no auth needed
        // to view or submit (a fresh visitor has no account yet), same
        // as the public Start-a-Trial form's own lack of a guest gate.
        Route::prefix('apply')->name('apply.')->group(function () {
            $apply = \App\Http\Controllers\Admissions\PublicApplicationController::class;
            Route::get('/', [$apply, 'start'])->name('start');
            Route::post('/', [$apply, 'register'])->middleware('throttle:10,1')->name('submit');
            // Signed (Phase 1d): an applicant has no account/password yet
            // at this point, so this link (generated once at register())
            // is the only proof of ownership until credentials exist.
            // Same URI for both verbs deliberately, so one signed link
            // works to view status AND to submit fee evidence.
            Route::get('/{applicant}/status', [$apply, 'status'])->middleware('signed')->name('status');
            Route::post('/{applicant}/status', [$apply, 'submitFeeEvidence'])->middleware(['signed', 'throttle:10,1'])->name('status.evidence');
        });
    });

    // Impersonation handoff landing point (see SchoolAdminController::
    // impersonate()'s docblock): neither 'guest' nor 'auth', the browser
    // is not yet authenticated on this host at this point, only the
    // signature proves the request is legitimate.
    Route::get('/impersonate/consume/{user}', [SchoolAdminController::class, 'consumeImpersonation'])
        ->middleware('signed')->name('school.impersonate.consume');

    Route::middleware(['auth', 'school_member', 'require_password_change'])->group(function () {
        Route::get('/dashboard', [SchoolPortalController::class, 'dashboard'])->name('school.dashboard');
        Route::post('/session/end-impersonation', [SchoolAdminController::class, 'endImpersonation'])->name('session.end-impersonation');

        Route::prefix('help')->name('school.knowledge-base.')->group(function () {
            $kb = \App\Http\Controllers\School\KnowledgeBaseController::class;
            Route::get('/', [$kb, 'index'])->name('index');
            Route::get('/{slug}', [$kb, 'show'])->name('show');
        });

        // Students
        Route::get('/students', [\App\Http\Controllers\School\StudentController::class, 'index'])->name('school.students');
        Route::get('/students/import', [\App\Http\Controllers\School\StudentController::class, 'importForm'])->name('school.students.import');
        Route::post('/students/import', [\App\Http\Controllers\School\StudentController::class, 'import'])->name('school.students.import.run');
        Route::get('/students/template.csv', [\App\Http\Controllers\School\StudentController::class, 'template'])->name('school.students.template');
        Route::get('/students/export.csv', [\App\Http\Controllers\School\StudentController::class, 'export'])->name('school.students.export');
        Route::get('/students/settings', [\App\Http\Controllers\School\StudentController::class, 'editSettings'])->name('school.students.settings.edit');
        Route::post('/students/settings', [\App\Http\Controllers\School\StudentController::class, 'updateSettings'])->name('school.students.settings.update');
        Route::get('/students/create', [\App\Http\Controllers\School\StudentController::class, 'create'])->name('school.students.create');
        Route::post('/students/create', [\App\Http\Controllers\School\StudentController::class, 'store'])->name('school.students.store');
        Route::post('/students/bulk-action', [\App\Http\Controllers\School\StudentController::class, 'bulkAction'])->name('school.students.bulk-action');
        Route::get('/students/trashed', [\App\Http\Controllers\School\StudentController::class, 'trashed'])->name('school.students.trashed');
        Route::post('/students/trashed/bulk-action', [\App\Http\Controllers\School\StudentController::class, 'trashedBulkAction'])->name('school.students.trashed.bulk-action');
        Route::get('/students/{studentId}', [\App\Http\Controllers\School\StudentController::class, 'show'])->name('school.students.show');
        Route::get('/students/{studentId}/edit', [\App\Http\Controllers\School\StudentController::class, 'edit'])->name('school.students.edit');
        Route::post('/students/{studentId}/edit', [\App\Http\Controllers\School\StudentController::class, 'update'])->name('school.students.update');
        Route::post('/students/{studentId}/photo', [\App\Http\Controllers\School\StudentController::class, 'updatePhoto'])->name('school.students.photo.update');
        Route::post('/students/{studentId}/report-feedback', [\App\Http\Controllers\School\StudentController::class, 'submitReportFeedback'])->name('school.students.report-feedback.store');
        Route::get('/student-portal/report', [\App\Http\Controllers\School\StudentController::class, 'myAcademicReport'])->name('school.students.my-academic-report');
        Route::get('/students/{studentId}/academic-report', [\App\Http\Controllers\School\StudentController::class, 'academicReport'])->name('school.students.academic-report');

        // A parent's requested change to their child's profile, class
        // teacher then school admin approval (user request 2026-07-26).
        Route::prefix('students/change-requests')->name('school.students.profile-change.')->group(function () {
            $changes = \App\Http\Controllers\School\StudentProfileChangeController::class;
            Route::get('/class-teacher', [$changes, 'classTeacherIndex'])->name('class-teacher.index');
            Route::post('/{requestId}/class-teacher/approve', [$changes, 'classTeacherApprove'])->name('class-teacher.approve');
            Route::post('/{requestId}/class-teacher/reject', [$changes, 'classTeacherReject'])->name('class-teacher.reject');
            Route::post('/class-teacher/bulk-approve', [$changes, 'classTeacherBulkApprove'])->name('class-teacher.bulk-approve');
            Route::post('/class-teacher/bulk-reject', [$changes, 'classTeacherBulkReject'])->name('class-teacher.bulk-reject');
            Route::get('/admin', [$changes, 'adminIndex'])->name('admin.index');
            Route::post('/{requestId}/admin/approve', [$changes, 'adminApprove'])->name('admin.approve');
            Route::post('/{requestId}/admin/reject', [$changes, 'adminReject'])->name('admin.reject');
            Route::post('/admin/bulk-approve', [$changes, 'adminBulkApprove'])->name('admin.bulk-approve');
            Route::post('/admin/bulk-reject', [$changes, 'adminBulkReject'])->name('admin.bulk-reject');
        });
        // A single admin oversight screen across every "someone is
        // waiting on a decision" flow (user request 2026-07-27).
        Route::prefix('approvals')->name('school.approvals.')->group(function () {
            $approvals = \App\Http\Controllers\School\ApprovalOversightController::class;
            Route::get('/', [$approvals, 'index'])->name('index');
            Route::get('/history', [$approvals, 'history'])->name('history');
            Route::post('/nudge', [$approvals, 'nudge'])->name('nudge');
            Route::post('/bulk-nudge', [$approvals, 'bulkNudge'])->name('bulk-nudge');
        });
        Route::get('/students/{studentId}/request-changes', [\App\Http\Controllers\School\StudentProfileChangeController::class, 'create'])->name('school.students.profile-change.create');
        Route::post('/students/{studentId}/request-changes', [\App\Http\Controllers\School\StudentProfileChangeController::class, 'store'])->name('school.students.profile-change.store');
        Route::post('/students/{studentId}/guardians', [\App\Http\Controllers\School\StudentController::class, 'storeGuardian'])->name('school.students.guardians.store');
        Route::post('/students/guardians/{guardianId}/edit', [\App\Http\Controllers\School\StudentController::class, 'updateGuardian'])->name('school.students.guardians.update');
        Route::delete('/students/guardians/{guardianId}', [\App\Http\Controllers\School\StudentController::class, 'destroyGuardian'])->name('school.students.guardians.destroy');
        Route::post('/students/guardians/{guardianId}/login', [\App\Http\Controllers\School\StudentController::class, 'createGuardianLogin'])->name('school.students.guardians.login.create');
        Route::post('/students/guardians/{guardianId}/login/reset-password', [\App\Http\Controllers\School\StudentController::class, 'resetGuardianLoginPassword'])->name('school.students.guardians.login.reset-password');
        Route::post('/students/guardians/{guardianId}/login/toggle-access', [\App\Http\Controllers\School\StudentController::class, 'toggleGuardianLoginAccess'])->name('school.students.guardians.login.toggle-access');
        Route::post('/students/{studentId}/login', [\App\Http\Controllers\School\StudentController::class, 'createStudentLogin'])->name('school.students.login.create');
        Route::post('/students/{studentId}/login/reset-password', [\App\Http\Controllers\School\StudentController::class, 'resetStudentLoginPassword'])->name('school.students.login.reset-password');
        Route::post('/students/{studentId}/login/toggle-access', [\App\Http\Controllers\School\StudentController::class, 'toggleStudentLoginAccess'])->name('school.students.login.toggle-access');

        Route::prefix('profile')->name('school.profile.')->group(function () {
            $profile = \App\Http\Controllers\School\ProfileController::class;
            Route::get('/', [$profile, 'edit'])->name('edit');
            Route::post('/', [$profile, 'update'])->name('update');
            Route::get('/approvals', [$profile, 'approvals'])->name('approvals');
            Route::post('/approvals/bulk-approve', [$profile, 'bulkApprove'])->name('bulk-approve');
            Route::post('/approvals/bulk-reject', [$profile, 'bulkReject'])->name('bulk-reject');
            Route::post('/approvals/{requestId}/approve', [$profile, 'approve'])->name('approve');
            Route::post('/approvals/{requestId}/reject', [$profile, 'reject'])->name('reject');
            Route::post('/approvals/reset/bulk-approve', [$profile, 'bulkApproveReset'])->name('reset.bulk-approve');
            Route::post('/approvals/reset/bulk-reject', [$profile, 'bulkRejectReset'])->name('reset.bulk-reject');
            Route::post('/approvals/reset/{requestId}/approve', [$profile, 'approveReset'])->name('reset.approve');
            Route::post('/approvals/reset/{requestId}/reject', [$profile, 'rejectReset'])->name('reset.reject');
        });
        Route::get('/password/change', [\App\Http\Controllers\School\ProfileController::class, 'changePasswordForm'])->name('school.password.change');
        Route::post('/password/change', [\App\Http\Controllers\School\ProfileController::class, 'updatePassword'])->name('school.password.change.update');

        Route::prefix('staff')->name('school.staff.')->group(function () {
            $staff = \App\Http\Controllers\School\StaffController::class;
            Route::get('/', [$staff, 'index'])->name('index');
            Route::post('/bulk-action', [$staff, 'bulkAction'])->name('bulk-action');
            Route::get('/create', [$staff, 'create'])->name('create');
            Route::post('/create', [$staff, 'store'])->name('store');
            Route::get('/trashed', [$staff, 'trashed'])->name('trashed');
            Route::post('/trashed/bulk-action', [$staff, 'trashedBulkAction'])->name('trashed.bulk-action');
            Route::get('/{staffId}', [$staff, 'show'])->name('show');
            Route::get('/{staffId}/edit', [$staff, 'edit'])->name('edit');
            Route::post('/{staffId}/edit', [$staff, 'update'])->name('update');
            Route::post('/{staffId}/login', [$staff, 'createLogin'])->name('login.create');
            Route::post('/{staffId}/login/reset-password', [$staff, 'resetLoginPassword'])->name('login.reset-password');
            Route::post('/{staffId}/login/toggle-access', [$staff, 'toggleLoginAccess'])->name('login.toggle-access');
        });

        // Staff Portal / Parent Portal (user directive 2026-07-16: browse
        // Section -> Class -> details, the same shape Student Portal
        // already uses), own top-level paths so they never collide with
        // /staff/{staffId} or a future /parents/{id} route.
        Route::prefix('staff-portal')->name('school.staff.')->group(function () {
            $staffDirectory = \App\Http\Controllers\School\StaffDirectoryController::class;
            Route::get('/', [$staffDirectory, 'index'])->name('directory');
            Route::get('/classes/{classId}', [$staffDirectory, 'showClass'])->name('directory-class');
            Route::get('/workload', [\App\Http\Controllers\School\TeacherWorkloadController::class, 'index'])->name('workload');
        });
        Route::prefix('parent-portal')->name('school.parents.')->group(function () {
            $parentDirectory = \App\Http\Controllers\School\ParentDirectoryController::class;
            Route::get('/', [$parentDirectory, 'index'])->name('directory');
            Route::get('/all', [$parentDirectory, 'all'])->name('all');
            Route::get('/classes/{classId}', [$parentDirectory, 'showClass'])->name('directory-class');
        });

        // User & Role Management (user directive 2026-07-15: per-user
        // "extra access" relocated here from the individual staff profile
        // page, plus creating custom roles and reassigning a user's role).
        Route::prefix('roles')->name('school.roles.')->group(function () {
            $roles = \App\Http\Controllers\School\RoleManagementController::class;
            Route::get('/', [$roles, 'index'])->name('index');
            Route::post('/', [$roles, 'storeRole'])->name('store');
            Route::post('/{roleId}/permissions', [$roles, 'updateRolePermissions'])->name('permissions.update');
            Route::post('/users/{userId}/role', [$roles, 'assignRole'])->name('users.assign');
            Route::post('/users/{userId}/extra-access', [$roles, 'updateExtraAccess'])->name('users.extra-access');
        });

        Route::prefix('houses')->name('school.houses.')->group(function () {
            $houses = \App\Http\Controllers\School\HouseController::class;
            Route::get('/', [$houses, 'index'])->name('index');
            Route::post('/', [$houses, 'store'])->name('store');
            Route::post('/apply-defaults', [$houses, 'applyDefaults'])->name('apply-defaults');
            Route::post('/distribute-members', [$houses, 'distributeMembers'])->name('distribute-members');
            Route::get('/assign', [$houses, 'assignForm'])->name('assign-form');
            Route::post('/assign', [$houses, 'assignBulk'])->name('assign-bulk');
            Route::get('/bulk-template', [$houses, 'bulkTemplate'])->name('bulk-template');
            Route::post('/bulk-assign', [$houses, 'bulkAssign'])->name('bulk-assign');
            Route::get('/staff-bulk-template', [$houses, 'staffBulkTemplate'])->name('staff-bulk-template');
            Route::post('/staff-bulk-assign', [$houses, 'staffBulkAssign'])->name('staff-bulk-assign');
            Route::get('/parents-bulk-template', [$houses, 'parentsBulkTemplate'])->name('parents-bulk-template');
            Route::post('/parents-bulk-assign', [$houses, 'parentsBulkAssign'])->name('parents-bulk-assign');
            Route::post('/bulk-action', [$houses, 'bulkAction'])->name('bulk-action');
            Route::get('/{houseId}', [$houses, 'show'])->name('show');
            Route::post('/{houseId}', [$houses, 'update'])->name('update');
            Route::post('/{houseId}/leadership', [$houses, 'updateLeadership'])->name('leadership');
            Route::post('/{houseId}/sponsors', [$houses, 'addSponsor'])->name('sponsors.store');
            Route::delete('/{houseId}/sponsors/{sponsorId}', [$houses, 'removeSponsor'])->name('sponsors.destroy');
            Route::post('/members/staff/{staffId}', [$houses, 'assignStaff'])->name('members.staff');
            Route::post('/members/parents/{guardianId}', [$houses, 'assignParent'])->name('members.parent');
            Route::post('/{houseId}/toggle-active', [$houses, 'toggleActive'])->name('toggle-active');
            Route::get('/{houseId}/members.csv', [$houses, 'downloadMembers'])->name('download-members');
            Route::delete('/{houseId}', [$houses, 'destroy'])->name('destroy');
        });
        Route::prefix('attendance')->name('school.attendance.')->group(function () {
            $attendance = \App\Http\Controllers\School\AttendanceController::class;
            Route::get('/', [$attendance, 'index'])->name('index');
            Route::get('/dashboard', [$attendance, 'dashboard'])->name('dashboard');
            Route::post('/mark', [$attendance, 'markClass'])->name('mark');
            Route::get('/bulk-template', [$attendance, 'bulkTemplate'])->name('bulk-template');
            Route::post('/bulk-upload', [$attendance, 'bulkUpload'])->name('bulk-upload');
            Route::get('/staff', [$attendance, 'staffIndex'])->name('staff');
            Route::post('/staff/mark', [$attendance, 'markStaff'])->name('staff.mark');
        });
        Route::prefix('search')->name('school.search.')->group(function () {
            Route::get('/', [\App\Http\Controllers\School\SearchController::class, 'index'])->name('index');
        });
        Route::prefix('announcements')->name('school.announcements.')->group(function () {
            $announcements = \App\Http\Controllers\School\AnnouncementController::class;
            Route::get('/', [$announcements, 'index'])->name('index');
            Route::get('/history', [$announcements, 'history'])->name('history');
            Route::get('/history/{announcementId}', [$announcements, 'showRecipients'])->name('history.show');
            Route::post('/history/{announcementId}/nudge', [$announcements, 'nudgeUnacknowledged'])->name('history.nudge');
            Route::get('/create', [$announcements, 'create'])->name('create');
            Route::post('/create', [$announcements, 'store'])->name('store');
            Route::get('/{announcementId}', [$announcements, 'show'])->name('show');
        });
        Route::prefix('messages')->name('school.messages.')->group(function () {
            $messages = \App\Http\Controllers\School\MessageController::class;
            Route::get('/', [$messages, 'index'])->name('index');
            Route::get('/create', [$messages, 'create'])->name('create');
            Route::post('/create', [$messages, 'store'])->name('store');
        });
        Route::prefix('settings/communication')->name('school.settings.communication.')->group(function () {
            $commSettings = \App\Http\Controllers\School\CommunicationSettingsController::class;
            Route::get('/', [$commSettings, 'edit'])->name('edit');
            Route::post('/', [$commSettings, 'update'])->name('update');
        });
        Route::prefix('chat')->name('school.chat.')->group(function () {
            $chat = \App\Http\Controllers\School\ChatController::class;
            Route::get('/', [$chat, 'index'])->name('index');
            Route::get('/start', [$chat, 'start'])->name('start');
            Route::get('/{threadId}', [$chat, 'show'])->name('show');
            Route::post('/{threadId}/messages', [$chat, 'store'])->name('messages.store');
        });
        Route::prefix('calendar')->name('school.calendar.')->group(function () {
            $calendar = \App\Http\Controllers\School\CalendarEventController::class;
            Route::get('/', [$calendar, 'index'])->name('index');
            Route::get('/create', [$calendar, 'create'])->name('create');
            Route::post('/create', [$calendar, 'store'])->name('store');
            Route::get('/{eventId}/edit', [$calendar, 'edit'])->name('edit');
            Route::post('/{eventId}/edit', [$calendar, 'update'])->name('update');
            Route::delete('/{eventId}', [$calendar, 'destroy'])->name('destroy');
            Route::post('/bulk-destroy', [$calendar, 'bulkDestroy'])->name('bulk-destroy');
        });
        Route::prefix('assignments')->name('school.assignments.')->group(function () {
            $assignments = \App\Http\Controllers\School\AssignmentController::class;
            Route::get('/', [$assignments, 'index'])->name('index');
            Route::get('/create', [$assignments, 'create'])->name('create');
            Route::post('/create', [$assignments, 'store'])->name('store');
            Route::get('/{assignmentId}', [$assignments, 'show'])->name('show');
            Route::post('/{assignmentId}/submit', [$assignments, 'submit'])->name('submit');
            Route::get('/{assignmentId}/submissions/{submissionId}/file', [$assignments, 'submissionFile'])->name('submissions.file');
            Route::delete('/{assignmentId}', [$assignments, 'destroy'])->name('destroy');
            Route::post('/bulk-destroy', [$assignments, 'bulkDestroy'])->name('bulk-destroy');
        });
        Route::get('/setup', [\App\Http\Controllers\School\SetupController::class, 'index'])->name('school.setup');
        Route::post('/notifications/{id}/read', [SchoolPortalController::class, 'markNotificationRead'])->name('school.notifications.read');
        Route::prefix('notifications')->name('school.notifications.')->group(function () {
            $notifications = \App\Http\Controllers\School\NotificationController::class;
            Route::get('/', [$notifications, 'index'])->name('index');
            Route::post('/bulk-action', [$notifications, 'bulkAction'])->name('bulk-action');
        });
        Route::get('/settings/branding', [SchoolPortalController::class, 'editBranding'])->name('school.branding.edit');
        Route::post('/settings/branding', [SchoolPortalController::class, 'updateBranding'])->name('school.branding.update');
        Route::get('/settings/profile', [SchoolPortalController::class, 'editProfile'])->name('school.settings.profile.edit');
        Route::post('/settings/profile', [SchoolPortalController::class, 'updateProfile'])->name('school.settings.profile.update');
        Route::get('/settings/dashboard', [SchoolPortalController::class, 'editDashboard'])->name('school.dashboard.edit');
        Route::post('/settings/dashboard', [SchoolPortalController::class, 'updateDashboard'])->name('school.dashboard.update');
        Route::get('/settings/landing-pages', [\App\Http\Controllers\School\LandingPageController::class, 'edit'])->name('school.settings.landing-pages.edit');
        Route::post('/settings/landing-pages', [\App\Http\Controllers\School\LandingPageController::class, 'update'])->name('school.settings.landing-pages.update');
        Route::prefix('settings/calendar')->name('school.settings.calendar.')->group(function () {
            $academics = \App\Http\Controllers\School\AcademicsController::class;
            Route::get('/', [$academics, 'editCalendar'])->name('edit');
            Route::post('/terms/{termId}/dates', [$academics, 'updateTermDates'])->name('terms.dates');
        });

        // Academics: sections, classes, and per-class subject offerings.
        Route::prefix('academics')->name('school.academics.')->group(function () {
            $academics = \App\Http\Controllers\School\AcademicsController::class;
            Route::get('/', [$academics, 'index'])->name('index');
            Route::post('/sessions', [$academics, 'storeSession'])->name('sessions.store');
            Route::post('/sessions/{sessionId}/make-current', [$academics, 'makeSessionCurrent'])->name('sessions.make-current');
            Route::post('/sessions/{sessionId}/archive', [$academics, 'archiveSession'])->name('sessions.archive');
            Route::post('/sessions/{sessionId}/unarchive', [$academics, 'unarchiveSession'])->name('sessions.unarchive');
            Route::post('/sessions/bulk-unarchive', [$academics, 'bulkUnarchiveSessions'])->name('sessions.bulk-unarchive');
            Route::post('/terms', [$academics, 'storeTerm'])->name('terms.store');
            Route::post('/terms/{termId}/make-current', [$academics, 'makeTermCurrent'])->name('terms.make-current');
            Route::post('/end-term', [$academics, 'endTerm'])->name('end-term');
            Route::get('/promotion-settings', [$academics, 'editPromotionSettings'])->name('promotion-settings.edit');
            Route::post('/promotion-settings', [$academics, 'updatePromotionSettings'])->name('promotion-settings.update');
            Route::post('/class-structure', [$academics, 'applyClassStructure'])->name('class-structure.apply');
            Route::post('/sections', [$academics, 'storeSection'])->name('sections.store');
            Route::post('/sections/{sectionId}', [$academics, 'updateSection'])->name('sections.update');
            Route::post('/classes', [$academics, 'storeClass'])->name('classes.store');
            Route::get('/classes/{classId}', [$academics, 'showClass'])->name('classes.show');
            Route::post('/classes/{classId}', [$academics, 'updateClass'])->name('classes.update');
            Route::post('/classes/{classId}/toggle-active', [$academics, 'toggleClassActive'])->name('classes.toggle-active');
            Route::delete('/classes/{classId}', [$academics, 'destroyClass'])->name('classes.destroy');
            Route::post('/arms', [$academics, 'storeArm'])->name('arms.store');
            Route::post('/arms/{classArmId}/rename', [$academics, 'renameArm'])->name('arms.rename');
            Route::post('/arms/{armId}/assign-teacher', [$academics, 'assignArmTeacher'])->name('arms.assign-teacher');
            Route::get('/classes/{classId}/subjects', [$academics, 'subjects'])->name('subjects');
            Route::post('/classes/{classId}/subjects/apply-defaults', [$academics, 'applyDefaults'])->name('subjects.apply-defaults');
            Route::post('/classes/{classId}/subjects/attach', [$academics, 'attachSubject'])->name('subjects.attach');
            Route::post('/classes/{classId}/subjects/new', [$academics, 'newSubject'])->name('subjects.new');
            Route::post('/classes/{classId}/subjects/bulk-detach', [$academics, 'bulkDetachSubjects'])->name('subjects.bulk-detach');
            Route::post('/classes/{classId}/subjects/{subjectId}', [$academics, 'updateSubject'])->name('subjects.update');
            Route::delete('/classes/{classId}/subjects/{subjectId}', [$academics, 'detachSubject'])->name('subjects.detach');
            Route::post('/classes/{classId}/subjects/{subjectId}/assign-teacher', [$academics, 'assignSubjectTeacher'])->name('subjects.assign-teacher');
            Route::get('/subject-teachers/template', [$academics, 'subjectTeachersTemplate'])->name('subject-teachers.template');
            Route::post('/subject-teachers/bulk-assign', [$academics, 'bulkAssignSubjectTeachers'])->name('subject-teachers.bulk-assign');
        });

        // Results entry: a teacher enters scores per component for a class + subject + term.
        Route::prefix('examinations')->name('school.examinations.')->group(function () {
            $examinations = \App\Http\Controllers\School\ExaminationController::class;
            Route::get('/', [$examinations, 'index'])->name('index');
            Route::get('/performance-dashboard', [$examinations, 'performanceDashboard'])->name('performance-dashboard');
            Route::get('/academic-insights', [\App\Http\Controllers\School\AcademicInsightsController::class, 'index'])->name('academic-insights');

            $narrative = \App\Http\Controllers\School\NarrativeEvaluationController::class;
            Route::get('/narrative-evaluation', [$narrative, 'index'])->name('narrative-evaluation');
            Route::post('/narrative-evaluation', [$narrative, 'update'])->name('narrative-evaluation.update');

            $reportCardCalc = \App\Http\Controllers\School\ReportCardCalculationController::class;
            Route::get('/report-card-calculation', [$reportCardCalc, 'index'])->name('report-card-calculation');
            Route::post('/report-card-calculation', [$reportCardCalc, 'update'])->name('report-card-calculation.update');

            Route::post('/scores', [$examinations, 'save'])->name('scores.save');
            Route::get('/scores/bulk-template', [$examinations, 'bulkTemplate'])->name('scores.bulk-template');
            Route::post('/scores/bulk-upload', [$examinations, 'bulkUpload'])->name('scores.bulk-upload');

            $assessment = \App\Http\Controllers\School\AssessmentController::class;
            Route::get('/structure', [$assessment, 'index'])->name('structure');
            Route::post('/structure/apply-defaults', [$assessment, 'applyDefaults'])->name('structure.apply-defaults');
            Route::post('/structure', [$assessment, 'store'])->name('structure.store');

            // Registered before the {componentId} wildcard route below,
            // which would otherwise greedily match "windows" as an id (the
            // same route-ordering gotcha documented in CLAUDE.md).
            $windows = \App\Http\Controllers\School\AssessmentWindowController::class;
            Route::post('/structure/windows', [$windows, 'updateWindow'])->name('structure.windows.update');
            Route::post('/structure/bulk-destroy', [$assessment, 'bulkDestroy'])->name('structure.bulk-destroy');

            Route::post('/structure/{componentId}', [$assessment, 'update'])->name('structure.update');
            Route::delete('/structure/{componentId}', [$assessment, 'destroy'])->name('structure.destroy');
            Route::post('/window-extensions', [$windows, 'store'])->name('window-extensions.store');
            Route::get('/window-extensions', [$windows, 'index'])->name('window-extensions.index');
            Route::post('/window-extensions/bulk-action', [$windows, 'bulkAction'])->name('window-extensions.bulk-action');
            Route::post('/window-extensions/{extensionId}/approve', [$windows, 'approve'])->name('window-extensions.approve');
            Route::post('/window-extensions/{extensionId}/reject', [$windows, 'reject'])->name('window-extensions.reject');

            $grading = \App\Http\Controllers\School\GradingController::class;
            Route::get('/grading', [$grading, 'index'])->name('grading');
            Route::post('/grading/apply-defaults', [$grading, 'applyDefaults'])->name('grading.apply-defaults');
            Route::post('/grading', [$grading, 'store'])->name('grading.store');
            Route::post('/grading/{scaleId}/make-default', [$grading, 'makeDefault'])->name('grading.make-default');
            Route::delete('/grading/{scaleId}', [$grading, 'destroy'])->name('grading.destroy');
            Route::post('/grading/{scaleId}/bands', [$grading, 'storeBand'])->name('grading.bands.store');
            Route::post('/grading/bands/bulk-destroy', [$grading, 'bulkDestroyBands'])->name('grading.bands.bulk-destroy');
            Route::post('/grading/bands/{bandId}', [$grading, 'updateBand'])->name('grading.bands.update');
            Route::delete('/grading/bands/{bandId}', [$grading, 'destroyBand'])->name('grading.bands.destroy');
            Route::post('/grading/sections/{sectionId}', [$grading, 'assignSection'])->name('grading.sections.assign');
            Route::post('/grading/classes/{classId}', [$grading, 'assignClass'])->name('grading.classes.assign');
        });

        // Foundation Finance slice (2026-07-17): Bank Transfer Payment
        // Option, Payment Evidence Upload, Payment Verification, Basic
        // Fee Records.
        Route::prefix('finance')->name('school.finance.')->group(function () {
            $feeItems = \App\Http\Controllers\School\FeeItemController::class;
            Route::get('/fee-items', [$feeItems, 'index'])->name('fee-items.index');
            Route::post('/fee-items', [$feeItems, 'store'])->name('fee-items.store');
            // Registered before the {feeItemId} wildcard route below, which
            // would otherwise greedily match "bulk-action" as an id.
            Route::post('/fee-items/bulk-action', [$feeItems, 'bulkAction'])->name('fee-items.bulk-action');
            Route::get('/fee-items/template', [$feeItems, 'template'])->name('fee-items.template');
            Route::post('/fee-items/bulk-upload', [$feeItems, 'bulkUpload'])->name('fee-items.bulk-upload');
            // The fee approval chain (user request 2026-07-25), registered
            // before {feeItemId} for the same reason as bulk-action above.
            Route::post('/fee-items/approval-settings', [$feeItems, 'updateApprovalSettings'])->name('fee-items.approval-settings');
            Route::post('/fee-items/{feeItemId}', [$feeItems, 'update'])->name('fee-items.update');
            Route::post('/fee-items/{feeItemId}/toggle-active', [$feeItems, 'toggleActive'])->name('fee-items.toggle-active');
            Route::post('/fee-items/{feeItemId}/approve', [$feeItems, 'approveFeeItem'])->name('fee-items.approve');
            Route::post('/fee-items/{feeItemId}/reject', [$feeItems, 'rejectFeeItem'])->name('fee-items.reject');
            Route::delete('/fee-items/{feeItemId}', [$feeItems, 'destroy'])->name('fee-items.destroy');
            Route::get('/fee-items/{feeItemId}', [$feeItems, 'show'])->name('fee-items.show');
            Route::post('/fee-items/{feeItemId}/installments', [$feeItems, 'storeInstallment'])->name('fee-items.installments.store');
            Route::post('/fee-items/installments/bulk-action', [$feeItems, 'bulkActionInstallments'])->name('fee-items.installments.bulk-action');
            Route::delete('/fee-items/installments/{installmentId}', [$feeItems, 'destroyInstallment'])->name('fee-items.installments.destroy');

            $payments = \App\Http\Controllers\School\PaymentController::class;
            Route::get('/payments', [$payments, 'index'])->name('payments.index');
            Route::get('/payments/create', [$payments, 'create'])->name('payments.create');
            Route::post('/payments', [$payments, 'store'])->name('payments.store');
            Route::post('/payments/bulk-action', [$payments, 'bulkAction'])->name('payments.bulk-action');
            Route::post('/payments/nudge', [$payments, 'nudgeUnpaid'])->name('payments.nudge');
            Route::post('/payments/due-date', [$payments, 'updateDueDate'])->name('payments.due-date');
            Route::post('/payments/active-term', [$payments, 'updateActiveTerm'])->name('payments.active-term');
            Route::get('/payments/classes/{classArmId}', [$payments, 'classDetail'])->name('payments.class-detail');
            Route::get('/payments/{paymentId}', [$payments, 'show'])->name('payments.show');
            Route::post('/payments/{paymentId}/verify', [$payments, 'verify'])->name('payments.verify');
            Route::post('/payments/{paymentId}/reject', [$payments, 'reject'])->name('payments.reject');
            Route::get('/payments/{paymentId}/evidence', [$payments, 'evidence'])->name('payments.evidence');

            // The school's own receiving bank account(s) (user request
            // 2026-07-25), managed from the Fee Records screen, shown to a
            // parent on the Submit Payment screen.
            $bankAccounts = \App\Http\Controllers\School\BankAccountController::class;
            Route::post('/bank-accounts', [$bankAccounts, 'store'])->name('bank-accounts.store');
            Route::post('/bank-accounts/bulk-action', [$bankAccounts, 'bulkAction'])->name('bank-accounts.bulk-action');
            Route::post('/bank-accounts/{bankAccountId}', [$bankAccounts, 'update'])->name('bank-accounts.update');
            Route::post('/bank-accounts/{bankAccountId}/toggle-active', [$bankAccounts, 'toggleActive'])->name('bank-accounts.toggle-active');
            Route::delete('/bank-accounts/{bankAccountId}', [$bankAccounts, 'destroy'])->name('bank-accounts.destroy');
        });

        // Timetable Management (Advance plan feature, user request
        // 2026-07-18, deferred then reversed the same day). "periods" and
        // "mine" registered before the {classArmId} wildcard-like routes
        // below, the same route-ordering gotcha documented in CLAUDE.md.
        Route::prefix('timetable')->name('school.timetable.')->group(function () {
            $timetable = \App\Http\Controllers\School\TimetableController::class;
            Route::get('/periods', [$timetable, 'periods'])->name('periods');
            Route::post('/periods', [$timetable, 'storePeriod'])->name('periods.store');
            Route::post('/periods/apply-defaults', [$timetable, 'applyDefaultPeriods'])->name('periods.apply-defaults');
            Route::post('/periods/bulk-destroy', [$timetable, 'bulkDestroyPeriods'])->name('periods.bulk-destroy');
            Route::post('/periods/fork-section', [$timetable, 'forkSectionPeriods'])->name('periods.fork-section');
            Route::post('/periods/reset-section', [$timetable, 'resetSectionPeriods'])->name('periods.reset-section');
            Route::post('/periods/{periodId}', [$timetable, 'updatePeriod'])->name('periods.update');
            Route::delete('/periods/{periodId}', [$timetable, 'destroyPeriod'])->name('periods.destroy');
            Route::get('/mine', [$timetable, 'mine'])->name('mine');
            Route::get('/mine/export', [$timetable, 'mineExport'])->name('mine.export');
            Route::get('/master', [$timetable, 'master'])->name('master');
            Route::get('/master/export', [$timetable, 'masterExport'])->name('master.export');
            Route::prefix('exam')->name('exam.')->group(function () use ($timetable) {
                Route::get('/papers', [$timetable, 'examPapers'])->name('papers');
                Route::post('/papers', [$timetable, 'examPapersUpdate'])->name('papers.update');
                // A parent/student's own read only exam timetable (user
                // request 2026-07-27), distinct from the admin builder
                // below at the bare exam.index route.
                Route::get('/mine', [$timetable, 'examMine'])->name('mine');
                Route::get('/', [$timetable, 'examIndex'])->name('index');
                Route::post('/', [$timetable, 'examSave'])->name('save');
                Route::get('/template', [$timetable, 'examTemplate'])->name('template');
                Route::post('/upload', [$timetable, 'examUpload'])->name('upload');
                Route::get('/export', [$timetable, 'examExport'])->name('export');
            });
            Route::get('/', [$timetable, 'index'])->name('index');
            Route::post('/{classArmId}', [$timetable, 'save'])->name('save');
        });

        // A school's own SKULOS subscription (2026-07-17): due date, real
        // amount owed, bank transfer payment submission.
        Route::prefix('subscription')->name('school.subscription.')->group(function () {
            $subscription = \App\Http\Controllers\School\SubscriptionController::class;
            Route::get('/', [$subscription, 'index'])->name('index');
            Route::post('/', [$subscription, 'store'])->name('store');
            Route::get('/payments/{paymentId}/evidence', [$subscription, 'evidence'])->name('evidence');
        });

        // Campuses.
        Route::get('/campuses', [\App\Http\Controllers\School\CampusController::class, 'index'])->name('school.campuses');
        Route::post('/campuses', [\App\Http\Controllers\School\CampusController::class, 'store'])->name('school.campuses.store');
        Route::delete('/campuses/{campusId}', [\App\Http\Controllers\School\CampusController::class, 'destroy'])->name('school.campuses.destroy');
        Route::post('/campuses/bulk-destroy', [\App\Http\Controllers\School\CampusController::class, 'bulkDestroy'])->name('school.campuses.bulk-destroy');

        // Report cards: browse by section/class, the review workflow, settings, preview, and the real per student render.
        Route::prefix('report-cards')->name('school.report-cards.')->group(function () {
            $reportCards = \App\Http\Controllers\School\ReportCardController::class;
            Route::get('/', [$reportCards, 'index'])->name('index');
            Route::get('/settings', [$reportCards, 'edit'])->name('edit');
            Route::post('/settings', [$reportCards, 'update'])->name('update');
            Route::get('/settings/layout', [$reportCards, 'editLayout'])->name('layout.edit');
            Route::post('/settings/layout', [$reportCards, 'updateLayout'])->name('layout.update');
            Route::post('/settings/layout/custom-blocks', [$reportCards, 'storeCustomBlock'])->name('layout.custom-blocks.store');
            Route::post('/settings/layout/custom-blocks/{blockId}', [$reportCards, 'updateCustomBlock'])->name('layout.custom-blocks.update');
            Route::delete('/settings/layout/custom-blocks/{blockId}', [$reportCards, 'destroyCustomBlock'])->name('layout.custom-blocks.destroy');
            Route::post('/review-stage', [$reportCards, 'updateReviewStage'])->name('review-stage');
            Route::post('/suggested-remark', [$reportCards, 'updateSuggestedRemark'])->name('suggested-remark');
            Route::post('/review-chain', [$reportCards, 'updateReviewChain'])->name('review-chain');
            Route::post('/sections/{sectionId}/signer', [$reportCards, 'updateSectionSigner'])->name('section.signer');
            Route::get('/preview/{template?}', [$reportCards, 'preview'])->name('preview');
            Route::get('/pending-review', [$reportCards, 'pendingReview'])->name('pending-review');
            Route::get('/bulk-generate', [$reportCards, 'bulkGenerateAllForm'])->name('bulk-generate');
            Route::post('/bulk-generate', [$reportCards, 'bulkGenerateAll'])->name('bulk-generate.submit');
            Route::get('/bulk-release', [$reportCards, 'bulkReleaseAllForm'])->name('bulk-release');
            Route::post('/bulk-release', [$reportCards, 'bulkReleaseAll'])->name('bulk-release.submit');
            Route::get('/classes/{classId}', [$reportCards, 'classIndex'])->name('class-index');
            Route::get('/classes/{classId}/print', [$reportCards, 'classPrint'])->name('class.print');
            Route::post('/classes/{classId}/generate', [$reportCards, 'generateClass'])->name('class.generate');
            Route::post('/classes/{classId}/bulk-assign', [$reportCards, 'bulkAssignReview'])->name('class.bulk-assign');
            Route::post('/classes/{classId}/default-reviewer', [$reportCards, 'updateDefaultReviewer'])->name('class.default-reviewer');
            Route::get('/classes/{classId}/entry', [$reportCards, 'classEntry'])->name('class.entry');
            Route::post('/classes/{classId}/entry', [$reportCards, 'classEntryUpdate'])->name('class.entry.update');
            Route::get('/classes/{classId}/entry/template', [$reportCards, 'classEntryTemplate'])->name('class.entry.template');
            Route::post('/classes/{classId}/entry/upload', [$reportCards, 'classEntryUpload'])->name('class.entry.upload');
            Route::post('/arms/{armId}/stamp-signature', [$reportCards, 'updateArmStampSignature'])->name('arm.stamp-signature');
            Route::post('/arms/reset-stamp-signature', [$reportCards, 'resetArmStampSignatures'])->name('arm.stamp-signature.reset-all');
            Route::post('/bulk-approve', [$reportCards, 'bulkApprove'])->name('bulk-approve');
            Route::post('/bulk-send-back', [$reportCards, 'bulkSendBack'])->name('bulk-send-back');
            Route::post('/bulk-nudge', [$reportCards, 'bulkNudge'])->name('bulk-nudge');
            Route::post('/{studentId}/generate', [$reportCards, 'generate'])->name('generate');
            Route::post('/{studentId}/assign', [$reportCards, 'assignReview'])->name('assign');
            Route::post('/{studentId}/send-back', [$reportCards, 'sendBack'])->name('send-back');
            Route::post('/{studentId}/approve', [$reportCards, 'approve'])->name('approve');
            Route::post('/{studentId}/promotion', [$reportCards, 'updatePromotion'])->name('promotion');
            Route::post('/{studentId}/release', [$reportCards, 'release'])->name('release');
            Route::post('/{studentId}/lock', [$reportCards, 'lock'])->name('lock');
            Route::post('/{studentId}/archive', [$reportCards, 'archive'])->name('archive');
            Route::post('/{studentId}/nudge', [$reportCards, 'nudge'])->name('nudge');
            Route::get('/{studentId}/{termId}', [$reportCards, 'show'])->name('show');
        });

        // Admissions module, Phase 1b: admin-side pipeline/window
        // configuration. Nested under 'settings' so its route names
        // (school.admissions.settings.*) never collide with the public
        // applicant-facing school.admissions.login/dashboard group
        // outside this authenticated block.
        Route::prefix('admissions/settings')->name('school.admissions.settings.')->group(function () {
            $admissionSettings = \App\Http\Controllers\School\AdmissionSettingsController::class;
            Route::get('/', [$admissionSettings, 'edit'])->name('edit');
            Route::post('/pipeline', [$admissionSettings, 'updatePipeline'])->name('pipeline');
            Route::post('/windows', [$admissionSettings, 'storeWindow'])->name('windows.store');
            Route::post('/windows/{windowId}/toggle', [$admissionSettings, 'toggleWindow'])->name('windows.toggle');
        });

        // Admissions module, Phase 1d: admin reviews an applicant's
        // uploaded application-fee evidence.
        Route::prefix('admissions/fees')->name('school.admissions.fees.')->group(function () {
            $feeReview = \App\Http\Controllers\School\AdmissionFeeReviewController::class;
            Route::get('/', [$feeReview, 'index'])->name('index');
            Route::get('/{paymentId}/evidence', [$feeReview, 'evidence'])->name('evidence');
            Route::post('/{paymentId}/verify', [$feeReview, 'verify'])->name('verify');
            Route::post('/{paymentId}/reject', [$feeReview, 'reject'])->name('reject');
        });

        // Behavioural/psychomotor trait catalog (user request 2026-07-18,
        // "the admin set it" whether each trait is a remark or a score).
        Route::prefix('report-cards/traits')->name('school.report-cards.traits.')->group(function () {
            $traits = \App\Http\Controllers\School\BehaviouralTraitController::class;
            Route::get('/', [$traits, 'index'])->name('index');
            Route::post('/', [$traits, 'store'])->name('store');
            Route::post('/bulk-action', [$traits, 'bulkAction'])->name('bulk-action');
            Route::post('/{traitId}', [$traits, 'update'])->name('update');
            Route::post('/{traitId}/toggle-active', [$traits, 'toggleActive'])->name('toggle-active');
        });

        // Student Reports (Foundation plan feature 'student_reports',
        // 2026-07-18): a class-wide broadsheet, distinct from a single
        // student's own Report Card document.
        Route::prefix('reports/students')->name('school.reports.students.')->group(function () {
            $studentReports = \App\Http\Controllers\School\StudentReportsController::class;
            Route::get('/', [$studentReports, 'index'])->name('index');
            Route::get('/export', [$studentReports, 'export'])->name('export');
        });

        // Custom Report Builder (Advance plan feature 'custom_report_builder', 2026-07-18).
        Route::prefix('reports/builder')->name('school.reports.builder.')->group(function () {
            $reportBuilder = \App\Http\Controllers\School\ReportBuilderController::class;
            Route::get('/', [$reportBuilder, 'index'])->name('index');
            Route::get('/export', [$reportBuilder, 'export'])->name('export');
        });

        // Subject Insights (Foundation plan feature 'subject_insights', 2026-07-22):
        // a class/subject teacher's own scoped view of the (class, subject)
        // pairs their TeachingAssignment rows say they teach, distinct from
        // the school wide Academic Insights above.
        Route::get('/reports/subject-insights', [\App\Http\Controllers\School\SubjectInsightsController::class, 'index'])
            ->name('school.reports.subject-insights.index');

        // Backup and restore.
        Route::prefix('backups')->name('school.backups.')->group(function () {
            $backups = \App\Http\Controllers\School\BackupController::class;
            Route::get('/', [$backups, 'index'])->name('index');
            Route::post('/', [$backups, 'store'])->name('store');
            Route::post('/schedule', [$backups, 'updateSchedule'])->name('schedule');
            Route::get('/{backupId}/download', [$backups, 'download'])->name('download');
            Route::post('/{backupId}/restore', [$backups, 'restore'])->name('restore');
        });

        Route::prefix('maintenance')->name('school.maintenance.')->group(function () {
            $maintenance = \App\Http\Controllers\School\MaintenanceController::class;
            Route::get('/', [$maintenance, 'index'])->name('index');
            Route::post('/optimize', [$maintenance, 'optimizeNow'])->name('optimize');
            Route::post('/retention', [$maintenance, 'runRetentionNow'])->name('retention');
        });

        // Mail settings (optional per-school SMTP override).
        Route::prefix('settings/mail')->name('school.settings.mail.')->group(function () {
            $mailSettings = \App\Http\Controllers\School\MailSettingsController::class;
            Route::get('/', [$mailSettings, 'edit'])->name('edit');
            Route::post('/', [$mailSettings, 'update'])->name('update');
            Route::post('/test', [$mailSettings, 'sendTest'])->name('test');
            Route::post('/welcome-emails', [$mailSettings, 'updateWelcomeEmails'])->name('welcome-emails.update');
        });

        // Idle timeout (optional per-school override of the platform default).
        Route::prefix('settings/idle-timeout')->name('school.settings.idle-timeout.')->group(function () {
            $idleTimeout = \App\Http\Controllers\School\IdleTimeoutController::class;
            Route::get('/', [$idleTimeout, 'edit'])->name('edit');
            Route::post('/', [$idleTimeout, 'update'])->name('update');
        });

        Route::prefix('settings/at-risk-thresholds')->name('school.settings.at-risk-thresholds.')->group(function () {
            $atRisk = \App\Http\Controllers\School\AtRiskThresholdController::class;
            Route::get('/', [$atRisk, 'edit'])->name('edit');
            Route::post('/', [$atRisk, 'update'])->name('update');
        });

        // Retention windows (optional per-school override of the platform default).
        Route::prefix('settings/retention')->name('school.settings.retention.')->group(function () {
            $retention = \App\Http\Controllers\School\RetentionController::class;
            Route::get('/', [$retention, 'edit'])->name('edit');
            Route::post('/', [$retention, 'update'])->name('update');
        });

        // Admin-defined fields, assignable to Student/Staff/Guardian forms
        // (user request 2026-07-22).
        Route::prefix('settings/custom-fields')->name('school.settings.custom-fields.')->group(function () {
            $customFields = \App\Http\Controllers\School\CustomFieldController::class;
            Route::get('/', [$customFields, 'index'])->name('index');
            Route::post('/', [$customFields, 'store'])->name('store');
            Route::post('/{fieldId}/edit', [$customFields, 'update'])->name('update');
            Route::delete('/{fieldId}', [$customFields, 'destroy'])->name('destroy');
            Route::post('/bulk-action', [$customFields, 'bulkAction'])->name('bulk-action');
        });
    });
});
