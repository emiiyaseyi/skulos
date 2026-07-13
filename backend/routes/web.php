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
        Route::get('/schools/{school}/overview', [SchoolAdminController::class, 'overview'])->name('schools.overview');
        Route::post('/schools/{school}/billing-date', [SchoolAdminController::class, 'updateBillingDate'])->name('schools.billing-date');
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
        Route::post('/schools/{school}/backups/schedule', [\App\Http\Controllers\Admin\BackupAdminController::class, 'updateSchedule'])->name('schools.backups.schedule');
        Route::get('/schools/{school}/backups/{backupId}/download', [\App\Http\Controllers\Admin\BackupAdminController::class, 'download'])->name('schools.backups.download');
        Route::post('/schools/{school}/backups/{backupId}/restore', [\App\Http\Controllers\Admin\BackupAdminController::class, 'restore'])->name('schools.backups.restore');

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
Route::post('/session/end-impersonation', [SchoolAdminController::class, 'endImpersonation'])
    ->middleware('auth')->name('session.end-impersonation');

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
        Route::post('/students/{studentId}/guardians', [\App\Http\Controllers\School\StudentController::class, 'storeGuardian'])->name('school.students.guardians.store');
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
            Route::post('/approvals/{requestId}/approve', [$profile, 'approve'])->name('approve');
            Route::post('/approvals/{requestId}/reject', [$profile, 'reject'])->name('reject');
        });

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
            Route::post('/{staffId}/extra-access', [$staff, 'updateExtraAccess'])->name('extra-access.update');
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
        Route::get('/setup', [\App\Http\Controllers\School\SetupController::class, 'index'])->name('school.setup');
        Route::post('/notifications/{id}/read', [SchoolPortalController::class, 'markNotificationRead'])->name('school.notifications.read');
        Route::prefix('notifications')->name('school.notifications.')->group(function () {
            $notifications = \App\Http\Controllers\School\NotificationController::class;
            Route::get('/', [$notifications, 'index'])->name('index');
            Route::post('/bulk-action', [$notifications, 'bulkAction'])->name('bulk-action');
        });
        Route::get('/settings/branding', [SchoolPortalController::class, 'editBranding'])->name('school.branding.edit');
        Route::post('/settings/branding', [SchoolPortalController::class, 'updateBranding'])->name('school.branding.update');
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
            Route::post('/arms/{armId}/assign-teacher', [$academics, 'assignArmTeacher'])->name('arms.assign-teacher');
            Route::get('/classes/{classId}/subjects', [$academics, 'subjects'])->name('subjects');
            Route::post('/classes/{classId}/subjects/apply-defaults', [$academics, 'applyDefaults'])->name('subjects.apply-defaults');
            Route::post('/classes/{classId}/subjects/attach', [$academics, 'attachSubject'])->name('subjects.attach');
            Route::post('/classes/{classId}/subjects/new', [$academics, 'newSubject'])->name('subjects.new');
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
            Route::post('/scores', [$examinations, 'save'])->name('scores.save');
            Route::get('/scores/bulk-template', [$examinations, 'bulkTemplate'])->name('scores.bulk-template');
            Route::post('/scores/bulk-upload', [$examinations, 'bulkUpload'])->name('scores.bulk-upload');

            $assessment = \App\Http\Controllers\School\AssessmentController::class;
            Route::get('/structure', [$assessment, 'index'])->name('structure');
            Route::post('/structure/apply-defaults', [$assessment, 'applyDefaults'])->name('structure.apply-defaults');
            Route::post('/structure', [$assessment, 'store'])->name('structure.store');
            Route::post('/structure/{componentId}', [$assessment, 'update'])->name('structure.update');
            Route::delete('/structure/{componentId}', [$assessment, 'destroy'])->name('structure.destroy');

            $grading = \App\Http\Controllers\School\GradingController::class;
            Route::get('/grading', [$grading, 'index'])->name('grading');
            Route::post('/grading/apply-defaults', [$grading, 'applyDefaults'])->name('grading.apply-defaults');
            Route::post('/grading', [$grading, 'store'])->name('grading.store');
            Route::post('/grading/{scaleId}/make-default', [$grading, 'makeDefault'])->name('grading.make-default');
            Route::delete('/grading/{scaleId}', [$grading, 'destroy'])->name('grading.destroy');
            Route::post('/grading/{scaleId}/bands', [$grading, 'storeBand'])->name('grading.bands.store');
            Route::post('/grading/bands/{bandId}', [$grading, 'updateBand'])->name('grading.bands.update');
            Route::delete('/grading/bands/{bandId}', [$grading, 'destroyBand'])->name('grading.bands.destroy');
        });

        // Campuses.
        Route::get('/campuses', [\App\Http\Controllers\School\CampusController::class, 'index'])->name('school.campuses');
        Route::post('/campuses', [\App\Http\Controllers\School\CampusController::class, 'store'])->name('school.campuses.store');
        Route::delete('/campuses/{campusId}', [\App\Http\Controllers\School\CampusController::class, 'destroy'])->name('school.campuses.destroy');

        // Report cards: browse by section/class, the review workflow, settings, preview, and the real per student render.
        Route::prefix('report-cards')->name('school.report-cards.')->group(function () {
            $reportCards = \App\Http\Controllers\School\ReportCardController::class;
            Route::get('/', [$reportCards, 'index'])->name('index');
            Route::get('/settings', [$reportCards, 'edit'])->name('edit');
            Route::post('/settings', [$reportCards, 'update'])->name('update');
            Route::post('/review-stage', [$reportCards, 'updateReviewStage'])->name('review-stage');
            Route::post('/review-chain', [$reportCards, 'updateReviewChain'])->name('review-chain');
            Route::get('/preview/{template?}', [$reportCards, 'preview'])->name('preview');
            Route::get('/pending-review', [$reportCards, 'pendingReview'])->name('pending-review');
            Route::get('/classes/{classId}', [$reportCards, 'classIndex'])->name('class-index');
            Route::post('/classes/{classId}/generate', [$reportCards, 'generateClass'])->name('class.generate');
            Route::post('/classes/{classId}/bulk-assign', [$reportCards, 'bulkAssignReview'])->name('class.bulk-assign');
            Route::post('/classes/{classId}/default-reviewer', [$reportCards, 'updateDefaultReviewer'])->name('class.default-reviewer');
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
        });

        // Idle timeout (optional per-school override of the platform default).
        Route::prefix('settings/idle-timeout')->name('school.settings.idle-timeout.')->group(function () {
            $idleTimeout = \App\Http\Controllers\School\IdleTimeoutController::class;
            Route::get('/', [$idleTimeout, 'edit'])->name('edit');
            Route::post('/', [$idleTimeout, 'update'])->name('update');
        });

        // Retention windows (optional per-school override of the platform default).
        Route::prefix('settings/retention')->name('school.settings.retention.')->group(function () {
            $retention = \App\Http\Controllers\School\RetentionController::class;
            Route::get('/', [$retention, 'edit'])->name('edit');
            Route::post('/', [$retention, 'update'])->name('update');
        });
    });
});
