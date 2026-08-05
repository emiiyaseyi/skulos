@php
  $slug = $school?->slug;
  $chev = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';

  /*
   * A class or subject teacher (user directive 2026-07-12, live QA:
   * "should not be seeing subjects and classes, houses, session and
   * terms, assessment structure, calendar, all admin features,
   * communication, finance and settings... except a new feature was
   * given to them") only ever sees the plan-feature-catalog items their
   * own role actually holds a real permission for, everything else in
   * the loop below is skipped for them outright. Every other role's nav
   * is completely unaffected, this only ever narrows what was already
   * shown, and only for the two roles the report was about.
   */
  $navUser = auth()->user();
  $navRbac = app(\App\Services\Rbac\Rbac::class);
  $scopedRoleSlug = ($school && $navUser) ? optional($navUser->roleIn($school))->slug : null;
  $isTeacherScoped = $scopedRoleSlug && in_array($scopedRoleSlug, config('skulos.teacher_scoped_roles', []), true);
  /*
   * A parent or student (user request 2026-07-13: "BUILD THE FULL
   * STUDENT AND PARENT PORTAL") gets the exact same restricted-nav
   * treatment as a scoped teacher, everything below reuses
   * $isRestrictedNav rather than $isTeacherScoped directly, they hold
   * students.view too (for their own family only, see FamilyScope) but
   * should never see Houses, Classes & Subjects, Settings, or any other
   * admin-only item any more than a scoped teacher does.
   */
  $isFamilyScoped = $scopedRoleSlug && in_array($scopedRoleSlug, config('skulos.family_scoped_roles', []), true);
  $isRestrictedNav = $isTeacherScoped || $isFamilyScoped;
  // These stay hidden from a scoped role even though the underlying
  // permission code (usually students.view) happens to be shared with a
  // screen they ARE allowed, e.g. Houses shares students.view with the
  // Students screen itself.
  $alwaysHiddenForScoped = [
    'school_houses', 'school_sections', 'classes_arms', 'subject_management',
    'academic_sessions_terms', 'student_promotion', 'unlimited_grading_systems',
    'school_profile', 'school_branding',
  ];

  // Family-scoped only (NOT teacher-scoped: a teacher has no other nav
  // entry into Students at all, this generic catalog line is their only
  // path there). A parent/student already gets their own explicit "My
  // Children"/"My Report" line into the exact same school.students
  // route (below), so the generic "Student Portal" catalog line is a
  // real duplicate for them (user report 2026-07-28: "no need of having
  // student portal under parents menu option since we already have my
  // children menu already and they have same content").
  $alwaysHiddenForFamilyScoped = ['student_portal', 'student_records', 'export_to_excel', 'bulk_export', 'bulk_import'];

  $link = function($routeName, $label, $icon) use ($cur, $slug) {
    $active = $cur === $routeName;
    $url = ($slug && \Illuminate\Support\Facades\Route::has($routeName)) ? route($routeName, ['school' => $slug]) : '#';

    // Label first, icon last (Design Lookbook v4 nav rule: "sidebar
    // labels LEFT, monogram icons RIGHT" — an explicit exception to the
    // project's general no-left-alignment rule, since a nav list is
    // scanned by its label). nav-admin.blade.php is a separate file and
    // keeps its own icon-left order untouched.
    return [
      'active' => $active,
      'html' => '<a class="side-link'.($active ? ' active' : '').'" href="'.$url.'">'.
        '<span>'.$label.'</span>'.
        view('layouts.partials.icon', ['name' => $icon])->render().
        '</a>',
    ];
  };
  $soonLink = function($label, $icon) {
    return [
      'active' => false,
      'html' => '<a class="side-link" href="#">'.
        '<span>'.$label.'</span><span class="soon">Soon</span>'.
        view('layouts.partials.icon', ['name' => $icon])->render().
        '</a>',
    ];
  };

  /*
   * Every menu and sub menu entry is read live from what is actually set
   * under Billing (user request 2026-07-11, repeated and corrected
   * several times: "it must check what is under billing, what are the
   * features under each plan, features ticked for the school or plan...
   * I need it to speak to the billing section under Platform
   * administration as set"). Nothing here is a hardcoded "this school
   * gets this menu", every group is one of the platform's own
   * config('skulos.plan_feature_catalog') categories (the exact same
   * categories and feature checklist the platform admin ticks on the
   * Billing plan editor), in the same order, and every single feature
   * the school's plan or an ad hoc grant includes is checked and shown,
   * either as a real link if that screen is built, or an individually
   * named "Soon" line if it is not, not a blended category level
   * summary. A feature not included is not shown at all. Downgrading a
   * plan or revoking an ad hoc feature takes effect on the very next
   * page load, School::hasFeature()/hasAnyFeatureInCategory() always
   * read the school's current Plan row and any BillingController
   * grantFeature()/SchoolAddOn state fresh, nothing is cached here.
   *
   * "support" is excluded except for Knowledge Base (2026-07-21, a real
   * screen now exists for it): the rest of it (Standard Support, Priority
   * Email Support, Staff Onboarding & Training, ...) are service
   * commitments, not screens a user navigates to, listing them as
   * "Soon" would misleadingly imply an unbuilt page is coming.
   */
  $builtRoutes = [
    // feature key => [routeName, label, icon]
    'dashboard' => ['school.dashboard', 'Dashboard', 'dashboard'],
    'knowledge_base' => ['school.knowledge-base.index', 'Knowledge Base', 'support'],
    // "Student Records" and "Student Portal" merged into one nav line
    // (user directive 2026-07-16: "UNDER ACADEMICS, WE HAVE STUDENT AND
    // STUDENT PORTAL, MERGE WHAT WE HAVE UNDER STUDENT INTO STUDENT
    // PORTAL SO THEY ARE ONE"), the exact same merge pattern already used
    // for Staff/Teacher Portal: both keys share the "Student Portal"
    // label and the same route, whichever renders first wins, the
    // dedupe below (no longer bypassed for student_portal, see
    // $alwaysOwnLine) stops the second from rendering a duplicate.
    'student_records' => ['school.students', 'Student Portal', 'students'],
    // Both real, but the same real page (user directive 2026-07-16,
    // "ALL THERE": build out remaining Reports & Analytics "Soon"
    // items). The actual capability, a CSV export of the visible
    // student list, is a button on the Students screen itself
    // (StudentController::export()), not a separate report page, so
    // whichever of these two keys a plan includes just resolves to the
    // Students screen the same way "Student Portal" already does,
    // rather than adding a confusing second nav line whose click would
    // immediately trigger a file download with no page behind it.
    'export_to_excel' => ['school.students', 'Student Portal', 'students'],
    'bulk_export' => ['school.students', 'Student Portal', 'students'],
    // Used to be its own "Import Students" nav line pointing at
    // school.students.import (StudentController::import()), a real,
    // dedicated CSV upload screen distinct from the plain Students
    // browse screen. User directive 2026-07-22: "students/import should
    // not be a menu option under reports and analytics since it can be
    // found under the student portal. Note, you are only removing it
    // from the menu side, page content and function still remains" — so
    // this now collapses into "Student Portal" the same way
    // export_to_excel/bulk_export already do, the route/controller/page
    // are completely unchanged and untouched, reached from the "Bulk
    // import" button already on the Students screen itself.
    'bulk_import' => ['school.students', 'Student Portal', 'students'],
    'academic_calendar' => ['school.calendar.index', 'Calendar', 'calendar'],
    // "Teacher Portal" and "Staff Records" are the same screen, seen from
    // two different plan-feature names (user directive 2026-07-15: "STAFF
    // AND TEACHER PORTAL SHOULD BE MERGE INTO ONE AND RENAMED STAFF
    // PORTAL"), so both keys share one label and one route, whichever is
    // processed first in the catalog loop renders it, the dedupe below
    // stops the second one from rendering a duplicate. Both now point at
    // the real Section -> Class -> staff browse screen (user directive
    // 2026-07-16: "STAFF PORTAL SHOULD BE CATEGORIZED PROPERLY FROM
    // SECTION, CLASS THEN TO THE STAFF DETAILS AS WE HAVE IT UNDER
    // STUDENT PORTAL"), the old flat list stays one click away on that
    // page ("Full staff list"), not lost. "Student Portal" still points
    // at the Students screen, an admin never "visits" that portal, a
    // portal is what a student sees once they sign in, which already
    // exists. "Parent Portal" is its own real browse screen now too (was
    // wrongly aliased to the same Students route as Student Portal until
    // 2026-07-16: "WHY IS PARENT PORTAL HAVING SAME LINK AS STUDENT
    // PORTAL - FIX IT").
    'staff_records' => ['school.staff.directory', 'Staff Portal', 'staff'],
    'teacher_portal' => ['school.staff.directory', 'Staff Portal', 'staff'],
    // Relabeled from "User & Role Management" to "Roles & Permissions"
    // (2026-07-17) to match the exact leaf name in the user's Settings >
    // Users tree; this is only the nav link's own text, the catalog
    // feature's own label ('User &amp; Role Management' in
    // config('skulos.plan_feature_catalog'), used by the Billing plan
    // editor checklist) is untouched.
    'user_role_management' => ['school.roles.index', 'Roles &amp; Permissions', 'roles'],
    'parent_portal' => ['school.parents.directory', 'Parent Portal', 'parents'],
    'student_portal' => ['school.students', 'Student Portal', 'students'],
    'attendance_management' => ['school.attendance.index', 'Attendance', 'attendance'],
    'attendance_dashboard' => ['school.attendance.dashboard', 'Attendance Dashboard', 'stats'],
    // Same real screen as attendance_dashboard above (2026-07-18): a real
    // per-class/per-student attendance breakdown already exists there,
    // "Attendance Reports" is not a separate, distinct capability.
    'attendance_reports' => ['school.attendance.dashboard', 'Attendance Dashboard', 'stats'],
    'student_performance_dashboard' => ['school.examinations.performance-dashboard', 'Student Performance Dashboard', 'trend'],
    // Same real screen as student_performance_dashboard above (2026-07-18):
    // it already IS a school-wide analytics dashboard (score distributions,
    // at-risk students, subject/class breakdowns), the catalog's "Basic"
    // vs "Student Performance" naming describes the same one built screen
    // at two different plan tiers, not two different screens.
    'basic_analytics_dashboard' => ['school.examinations.performance-dashboard', 'Student Performance Dashboard', 'trend'],
    // Fully built (NotificationController: categories, search, pin,
    // archive, bulk actions) since 2026-07-12 but never wired into this
    // catalog, so it only ever showed as "Soon" despite already working
    // (user directive 2026-07-16: build out the remaining Reports &
    // Analytics/Communication "Soon" items).
    'in_app_notifications' => ['school.notifications.index', 'Notifications', 'comms'],
    'announcements' => ['school.announcements.index', 'Announcements', 'mail'],
    'parent_broadcast' => ['school.messages.index', 'Messages', 'chat'],
    'communication_history' => ['school.announcements.history', 'Communication History', 'history'],
    // Already fully built end to end, just never wired into this catalog
    // (2026-07-18): AnnouncementController::store() ->
    // NotificationService::dispatchAnnouncement() ->
    // CommunicationService::emailAnnouncement() -> a real Mailable,
    // logged per recipient in communication_logs, all pre-existing. Only
    // the school-facing on/off switch was missing (channelEnabled('email')
    // previously read a hardcoded config default with no override), added
    // to this same Communication Settings screen alongside it.
    'email_notifications' => ['school.settings.communication.edit', 'Notifications', 'toggle'],
    // Same real screen as in_app_notifications above (2026-07-18, user
    // report: "I AM STILL SEEING COMING SOON UNDER SOME FEATURES"): the
    // scheduled skulos:notify-birthdays-anniversaries command (built
    // 2026-07-16) already creates real Notification rows the moment a
    // birthday/anniversary actually falls today, they show up right here,
    // there is no separate "Birthday Notifications" screen because there
    // is no separate destination for what this feature produces.
    'birthday_anniversary_notifications' => ['school.notifications.index', 'Notifications', 'comms'],
    'school_houses' => ['school.houses.index', 'Houses', 'house'],
    'school_sections' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'classes_arms' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'subject_management' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'academic_sessions_terms' => ['school.settings.calendar.edit', 'Sessions &amp; Terms', 'terms'],
    'continuous_assessment' => ['school.examinations.structure', 'Assessment Structure', 'structure'],
    // "Results" and "Report Cards" merged into one nav line (user
    // directive 2026-07-15), same merge pattern as Staff/Teacher Portal:
    // both feature keys share one label and route, whichever renders
    // first wins, the dedupe below stops a duplicate. Unlike that merge,
    // both sides here have a real, distinct, actively used screen, so the
    // Results screen (school.examinations.index) stays one click away via
    // a button on the Report Cards page instead of losing nav access
    // entirely, see ReportCardController::index()'s $canViewResults.
    'result_management' => ['school.report-cards.index', 'Report Cards', 'results'],
    'basic_report_cards' => ['school.report-cards.index', 'Report Cards', 'results'],
    // Same real screen as basic_report_cards above (2026-07-18): a report
    // card is already a printable page (browser print/"Save as PDF"
    // works on it as-is, same as any other printable document), "Export
    // to PDF" is not a separate generator/download screen.
    'export_to_pdf' => ['school.report-cards.index', 'Report Cards', 'results'],
    // Foundation gap closed 2026-07-18 (StudentReportsController): a real
    // class-wide broadsheet, distinct from result_management/
    // basic_report_cards above (a single student's own document).
    // Nested under Academic > Examinations alongside Enter Scores/Grading
    // (user directive 2026-07-18: "add a submenu for term broadsheets
    // too"), not its own top-level Reports & Analytics line any more.
    'student_reports' => ['school.reports.students.index', 'Term Broadsheets', 'broadsheet'],
    'unlimited_grading_systems' => ['school.examinations.grading', 'Grading', 'scale'],
    // Already fully built, just never wired into this catalog (2026-07-18):
    // ReportCardService::forStudent() already computes both overall
    // position/class size (shown by default, "overall_position" field)
    // and per-subject position (opt-in, "subject_position" field, modern
    // template) from ResultService::classBroadsheet()/subjectStats(); the
    // toggle for both lives on the existing Report settings screen.
    'class_position_ranking' => ['school.report-cards.edit', 'Report settings', 'sliders'],
    // Advance tier plan feature 'advanced_report_cards' wired 2026-07-18
    // to the behavioural/psychomotor traits catalog: a real, distinct
    // capability Foundation's plain "basic_report_cards" does not have
    // (Templates 5 and 6, physical stats, stamp/signature are all reached
    // from the same Report settings screen every plan already gets, but
    // the traits catalog itself, and the class teacher entry screen that
    // depends on it, only make sense once behavioural/psychomotor rating
    // is actually a feature the school pays for).
    'advanced_report_cards' => ['school.report-cards.traits.index', 'Behavioural Traits', 'traits'],
    // Already fully built, just never wired into this catalog (2026-07-18):
    // the drag-and-drop report card layout designer (ReportCardController::
    // editLayout()/updateLayout(), school.report-cards.layout.edit) is
    // exactly the freeform designer this catalog feature describes,
    // distinct from the 4 fixed templates every plan can already pick
    // between.
    'custom_report_card_designer' => ['school.report-cards.layout.edit', 'Report Card Designer', 'layout'],
    // Admin-tier roles (holding timetable.update via the blanket
    // timetable.* grant) browse and edit any class's grid; every scoped
    // role only ever holds timetable.view and sees their own schedule
    // instead (user request 2026-07-18, un-deferred and built the same
    // day: "only admin can set this").
    // Label reads "Timetable" for everyone now (user report 2026-07-22:
    // "let's change the menu name to Timetable and not My timetable"),
    // the "my own" framing lived in the screen's own subtitle/toggle
    // instead once a class teacher can also see their own class's full
    // grid from here, not just their personal schedule.
    'timetable_management' => $isRestrictedNav
        ? ['school.timetable.mine', 'Timetable', 'schedule']
        : ['school.timetable.index', 'Timetable', 'schedule'],
    'student_promotion' => ['school.settings.calendar.edit', 'Sessions &amp; Terms', 'terms'],
    // Repointed from school.campuses (2026-07-18): a real, dedicated
    // School Profile screen now exists (SchoolPortalController::
    // editProfile(), built 2026-07-17), so the feature key genuinely
    // named "School Profile" in the catalog now points at it instead of
    // the Campuses screen it was only ever standing in for. Campuses gets
    // its own unconditional link below, same as before.
    'school_profile' => ['school.settings.profile.edit', 'Profile', 'school'],
    'school_branding' => ['school.branding.edit', 'Branding', 'palette'],
    // Foundation Finance slice (2026-07-17): bank_transfer_payment_option/
    // payment_evidence_upload/payment_verification are all the same real
    // capability (submit or verify a bank transfer payment with evidence),
    // one screen, the same merge pattern already used for export_to_excel/
    // bulk_export above. basic_fee_records is the separate fee catalog
    // setup screen.
    'bank_transfer_payment_option' => ['school.finance.payments.index', 'Payments', 'billing'],
    'payment_evidence_upload' => ['school.finance.payments.index', 'Payments', 'billing'],
    'payment_verification' => ['school.finance.payments.index', 'Payments', 'billing'],
    'basic_fee_records' => ['school.finance.fee-items.index', 'Fee Records', 'fee'],
    // Advance tier plan feature 'installment_payment_plans' wired 2026-07-18:
    // same real screen as basic_fee_records (a fee item's own page there
    // is where the installment schedule is actually managed, per-fee-item,
    // once a school turns "Allow this fee to be paid in installments" on
    // for it), not a separate screen.
    'installment_payment_plans' => ['school.finance.fee-items.index', 'Fee Records', 'fee'],
    'custom_report_builder' => ['school.reports.builder.index', 'Report Builder', 'builder'],
    // Advance tier plan feature wired 2026-07-18: a whole school,
    // subject-level ranking + grade distribution, distinct from the
    // Student Performance Dashboard above (class/student level, current
    // term only).
    'academic_insights' => ['school.examinations.academic-insights', 'Academic Insights', 'search'],
    // Foundation plan feature wired 2026-07-22 (user request: "report and
    // analytics should have subject insights for class teacher and
    // subject teachers"): a teacher's own scoped view of only the
    // classes/subjects their TeachingAssignment rows say they teach,
    // distinct from Academic Insights above (school wide, leadership only).
    'subject_insights' => ['school.reports.subject-insights.index', 'Subject Insights', 'insight'],
    // Advance tier plan feature wired 2026-07-18: real teaching-load data
    // (TeachingAssignment + class teacher/assistant assignment), not a
    // timetable (Timetable Management stays deferred).
    'teacher_workload' => ['school.staff.workload', 'Teacher Workload', 'workload'],
    // Admissions module (2026-07-30/31 audit follow-up: real screens were
    // built this session — pipeline settings + admission windows, the
    // custom application form builder — but never mapped here, so a
    // school with the module enabled saw nothing in nav at all, despite
    // the automatic-appear mechanism itself already working correctly for
    // every other feature. 'applicant_tracking' now points at the real
    // pipeline board (Phase 1g, repointed from Fee Review the moment this
    // landed, same as school_profile's own repoint above once its real
    // screen existed). 'admission_letters' now repointed to Fee Review
    // itself (live feedback 2026-07-31: it wasn't reachable from any
    // submenu, only as a cross-link button on other admissions screens)
    // — real offer/rejection letter generation isn't built and isn't
    // planned, so this key was otherwise permanently unused.
    // 'online_document_upload' stays unmapped (renders as "Soon") until
    // document upload/approval (Phase 1f) is actually built.
    'online_admissions' => ['school.admissions.settings.edit', 'Admissions Settings', 'settings'],
    'admission_workflow' => ['school.admissions.form-builder.index', 'Admission Form Builder', 'builder'],
    'applicant_tracking' => ['school.admissions.pipeline', 'Applicants', 'students'],
    'admission_letters' => ['school.admissions.fees.index', 'Fee Review', 'billing'],
  ];

  // The report card browse/review page lives under Academic in the plan
  // feature catalog, but the user asked (2026-07-11) for it to appear
  // under Reports & Analytics in the nav instead, so its group placement
  // is overridden here regardless of which catalog category it was
  // ticked under. "Report settings" (the look/template page) used to be
  // gated on the pricier "Advanced Report Cards" catalog feature the
  // same way, but the user decided (2026-07-11) every plan should be
  // able to reach it, same as Backups or Mail Settings, so it is no
  // longer read from the catalog loop at all, see the unconditional
  // append below instead.
  // User directive 2026-07-15: "ASSESSMENT STRUCTURE, SESSIONS & TERM,
  // BRANDING, CAMPUSES AND HOUSES SHOULD BE MOVED TO SETTINGS", and
  // separately "STAFF PORTAL, PARENT AND STUDENT PORTAL SHOULD BE MOVED
  // TO ACADEMIC" (regardless of which catalog category, Administration,
  // they were originally ticked under).
  $forceGroup = [
    'basic_report_cards' => 'Reports & Analytics',
    'result_management' => 'Reports & Analytics',
    // Points at the same school.report-cards.edit route the always-shown
    // "Report settings" unconditional append below already renders, so
    // this must land in the same group that append targets (Reports &
    // Analytics), not "academic" (this feature's plain catalog category),
    // or the two would fight over which group "Report settings" appears
    // in depending on plan tier.
    'class_position_ranking' => 'Reports & Analytics',
    'advanced_report_cards' => 'Reports & Analytics',
    'custom_report_card_designer' => 'Reports & Analytics',
    // Nested under Academic > Examinations instead of its previous
    // Reports & Analytics category placement (user directive 2026-07-18:
    // "add a submenu for term broadsheets too").
    'student_reports' => 'Academic',
    // Same reasoning: points at school.settings.communication.edit, the
    // same route the unconditional Settings > Communication > Notifications
    // link below already renders (that block does not dedup against
    // $renderedRoutes the way the catalog loop does), so it must land in
    // Settings too, or an Advance-tier school would see it rendered
    // twice, once under the top-level Communication group and once under
    // Settings.
    'email_notifications' => 'Settings',
    'continuous_assessment' => 'Settings',
    'academic_sessions_terms' => 'Settings',
    'school_branding' => 'Settings',
    'school_profile' => 'Settings',
    'school_houses' => 'Settings',
    'staff_records' => 'Academic',
    'teacher_portal' => 'Academic',
    'parent_portal' => 'Academic',
    'student_portal' => 'Academic',
    // Settings nav restructure (user directive 2026-07-17, a full nested
    // tree: School/Users/Communication/Appearance/Security/System/
    // Analytics): Role Management previously rendered under
    // "Administration" (its plan-feature-catalog category by default,
    // never overridden), moved here to become Settings > Users > Roles &
    // Permissions per that tree.
    'user_role_management' => 'Settings',
  ];

  // Which of the 7 Settings sub-groups each forceGroup-to-Settings
  // feature key lands in, read alongside $forceGroup in the catalog loop
  // below. Every key that forces into 'Settings' must have an entry here.
  $forceSubgroup = [
    'school_profile' => 'School',
    'school_houses' => 'School',
    'academic_sessions_terms' => 'School',
    'continuous_assessment' => 'School',
    'school_branding' => 'Appearance',
    'user_role_management' => 'Users',
    'email_notifications' => 'Communication',
  ];
  // Populated by both the catalog loop and the unconditional block below,
  // in the exact order the user specified. Filtered down to whichever
  // sub-groups actually end up with content right before rendering.
  $settingsSubgroups = [
    'School' => [], 'Users' => [], 'Communication' => [], 'Appearance' => [],
    'Security' => [], 'System' => [], 'Analytics' => [],
  ];

  // Academic > Examinations (user directive 2026-07-18: "the examination
  // page is missing as a menu which should be under academics where
  // teachers can enter scores, it should have the grade page as a sub
  // menu under it, use the setting approach we use to achieve the
  // above"). Same nested-subsection shape as Settings above, but Academic
  // otherwise stays a flat list, so this one subgroup is rendered
  // separately and pushed in as a single pre-built entry right before
  // final assembly, rather than making the whole Academic group nested
  // the way Settings is.
  $academicSubgroups = ['Examinations' => []];

  // Dashboard stays pinned in Main regardless of which catalog category
  // it belongs to, it is the app's home page, not really a "feature" in
  // the marketing sense, worth keeping prominent rather than buried
  // inside a collapsed Administration submenu.
  $renderedRoutes = ['school.dashboard' => true];
  $groups = [
    // "Calendar" moved out of Main, merged with the "Academic Calendar"
    // plan feature (user directive 2026-07-16: "ACADEMIC CALENDAR AND
    // CALENDAR SHOULD BE MERGE AS ONE AND PLACED UNDER ACADEMICS"), see
    // 'academic_calendar' in $builtRoutes below, its own catalog category
    // is already 'academic', so no forceGroup override is needed.
    'Main' => [$link('school.dashboard', 'Dashboard', 'dashboard'), $link('school.setup', 'Guided Setup', 'compass')],
  ];

  foreach (config('skulos.plan_feature_catalog') as $categoryKey => $category) {
    // A school can get an optional module two independent ways: a full
    // Plan change (Plan::features, what hasFeature()/
    // hasAnyFeatureInCategory() check) or a direct per-school toggle
    // (BillingController::toggleFeature() -> Rbac::setModuleState(), no
    // plan change at all) — see feature_category_module_map's docblock
    // in config/skulos.php for why these need bridging here, found live
    // 2026-07-31 when a module toggled the second way stayed invisible
    // in nav despite real screens existing behind it.
    $categoryModuleCode = config("skulos.feature_category_module_map.{$categoryKey}");
    $categoryModuleEnabled = $school && $categoryModuleCode
      && $navRbac->moduleState($school, $categoryModuleCode) === \App\Models\Module::STATE_ENABLED;

    if (! $school?->hasAnyFeatureInCategory($categoryKey) && ! $categoryModuleEnabled) {
      continue;
    }

    foreach ($category['features'] as $featureKey => $featureLabel) {
      // The rest of "support" (Standard Support, Priority Email Support,
      // Staff Onboarding & Training, ...) stays excluded as service
      // commitments, not screens — see the docblock above. Knowledge
      // Base is the one exception (2026-07-21): a real in-app help
      // screen now exists for it, see $builtRoutes below.
      if ($categoryKey === 'support' && $featureKey !== 'knowledge_base') {
        continue;
      }
      // The module-toggle fallback only ever rescues a feature key that
      // has a REAL screen behind it (isset($builtRoutes[...]) below) —
      // it must not resurrect every OTHER marketing feature key in the
      // category as a phantom "Soon" placeholder too. "Soon" is reserved
      // for a feature the school's actual PLAN claims but that has no
      // screen yet; a bare module toggle makes no such claim about
      // anything beyond what is actually built (regression caught by
      // PlanFeatureNavTest's own "no Soon items" assertion for a fully
      // plan-featured school once this fallback was first added).
      $rescuedByModuleToggle = $categoryModuleEnabled && isset($builtRoutes[$featureKey]);
      if (! $school->hasFeature($featureKey) && ! $rescuedByModuleToggle) {
        continue;
      }
      if ($isRestrictedNav) {
        if (in_array($featureKey, $alwaysHiddenForScoped, true)) {
          continue;
        }
        if ($isFamilyScoped && in_array($featureKey, $alwaysHiddenForFamilyScoped, true)) {
          continue;
        }
        $requiredPerm = config("skulos.nav_permission_map.{$featureKey}");
        if (! $requiredPerm || ! $navRbac->check($navUser, $school, $requiredPerm)) {
          continue;
        }
      }
      $targetLabel = $forceGroup[$featureKey] ?? $category['label'];
      if (isset($builtRoutes[$featureKey])) {
        [$routeName, $label, $icon] = $builtRoutes[$featureKey];
        // "Parent Portal" intentionally renders its OWN line every time
        // (user report 2026-07-15: "I CANT SEE STUDENT AND PARENT PORTAL
        // UNDER THE MENU SECTION", the normal same-route dedupe below
        // vanished it entirely instead of showing as a real link, a
        // worse regression than the "Soon" it replaced). It now points
        // at its own distinct route (school.parents.directory, since
        // 2026-07-16) so this bypass is no longer strictly load-bearing
        // for it either, kept anyway as a harmless no-op safeguard.
        // "Staff Portal"/"Teacher Portal" and "Student Portal"/"Student
        // Records" are NOT in this list, per their respective merges
        // both collapse into one line, not two.
        $alwaysOwnLine = in_array($featureKey, ['parent_portal'], true);
        if (! $alwaysOwnLine) {
          if (isset($renderedRoutes[$routeName])) {
            continue; // several feature keys can point at the same already built screen
          }
          $renderedRoutes[$routeName] = true;
        }
        $entry = $link($routeName, $label, $icon);
      } else {
        $entry = $soonLink($featureLabel, 'settings');
      }
      // Settings items land in one of the 7 nested sub-groups instead of
      // a flat Settings list (see $settingsSubgroups above); Grading and
      // Term Broadsheets land in the new Academic > Examinations subgroup
      // instead of a flat entry (see $academicSubgroups above); everything
      // else keeps the existing flat single-level group behaviour.
      if ($targetLabel === 'Settings' && isset($forceSubgroup[$featureKey])) {
        $settingsSubgroups[$forceSubgroup[$featureKey]][] = $entry;
      } elseif (in_array($featureKey, ['unlimited_grading_systems', 'student_reports'], true)) {
        // Keyed, not appended, so the fixed display order below (Enter
        // Scores, Term Broadsheets, Grading) never depends on which
        // plan_feature_catalog category happens to iterate first.
        $academicSubgroups['Examinations'][$featureKey === 'unlimited_grading_systems' ? 'grading' : 'term_broadsheets'] = $entry;
      } else {
        $groups[$targetLabel][] = $entry;
      }
    }
  }

  // Assignments — moved from Academic to Learning Management (user
  // directive 2026-08-05: "let's move assignment to under learning
  // management"), superseding the 2026-07-16 placement in Academic.
  // Deliberately not routed through the plan-feature catalog loop above
  // (unlike everything else in Learning Management): 'assignments' is
  // not part of the Foundation or Advance launch plans' feature list,
  // so gating it there would silently remove an already-built,
  // already-visible screen from every real school on those tiers, a
  // pricing decision this nav change is not the place to make. Checked
  // by real RBAC permission instead, so it shows for a restricted
  // teacher/parent/student role exactly the same way it shows for an
  // unrestricted admin one, each still only ever sees their own scoped
  // assignments once they get to the actual screen (see
  // AssignmentController::index()'s TeacherScope/FamilyScope filtering).
  if ($navRbac->check($navUser, $school, 'assignments.view') && ! isset($renderedRoutes['school.assignments.index'])) {
    $renderedRoutes['school.assignments.index'] = true;
    // A parent gets the dedicated per-child cards screen instead of the
    // flat, all-children-merged list every other role sees (2026-08-05
    // directive, same shape as the assessment cards above).
    if ($scopedRoleSlug === 'parent') {
      $childAssignmentsLabel = "My Child's Assignments";
      if (app(\App\Services\Rbac\FamilyScope::class)->studentIds($navUser, $school)?->count() !== 1) {
        $childAssignmentsLabel = "My Children's Assignments";
      }
      $groups['Learning Management'][] = $link('school.assignments.mine', $childAssignmentsLabel, 'assignments');
    } else {
      $groups['Learning Management'][] = $link('school.assignments.index', 'Assignments', 'assignments');
    }
  }

  // SKULOS Assessment Hub, Phase 2h: a new top-level "Learning
  // Management" group (the user directly asked for this exact
  // placement — "the admission assessment should be sen under the
  // learning managent menu... so that all the needed seetings can be
  // done"), following the exact same pattern as Assignments just above:
  // 'assessment_hub' is not part of any Plan's feature catalog (same as
  // 'assignments'), so it is gated on a real RBAC permission check
  // instead of the plan_feature_catalog loop — Rbac::check() already
  // consults Rbac::moduleState() internally (see its own docblock), so
  // a school with the module disabled/hidden correctly sees nothing
  // here without a second explicit moduleState() call. "Take
  // Assessments" is every taker's own self-service entry (.view, also
  // granted to students/parents); every other link needs .create, the
  // same admin tier the question bank/paper builder/security profiles/
  // face approvals controllers already gate on (those screens expose
  // correct answers or other takers' live data).
  // Entry names follow the reference repo's own admin menu (user
  // directive 2026-07-31, with a screenshot of it: "IT SHOULD HAVE THE
  // SAME BACKEND AND FRONT END AS IT IS IN LEARNGUARD"). "Papers" was
  // removed outright — it was invented here, has no counterpart in the
  // reference, and already meant an exam-timetable row elsewhere in
  // SKULOS (see ExamPaper). Entries the reference has but SKULOS has
  // not built yet (Overview, Admin Backend, Score Matrix, Bulk
  // Assignment, Bulk Scheduling, Assessment Manager, Storage
  // Management, 360 Precheck Videos) are deliberately absent rather
  // than shown as dead links; they appear here as each real screen
  // lands.
  if ($navRbac->check($navUser, $school, 'assessment_hub.view')) {
    // A parent has no eligibility of their own to take anything — the
    // taker-facing screen makes no sense for them (2026-08-05 directive:
    // "parent view of assessments should be for each child"), so they
    // get the dedicated per-child cards screen instead, same singular/
    // plural label rule as "My Child(ren)" above.
    if ($scopedRoleSlug === 'parent') {
      $childAssessmentsLabel = "My Child's Assessments";
      if (app(\App\Services\Rbac\FamilyScope::class)->studentIds($navUser, $school)?->count() !== 1) {
        $childAssessmentsLabel = "My Children's Assessments";
      }
      $groups['Learning Management'][] = $link('school.cbt.parent.index', $childAssessmentsLabel, 'quiz');
    } else {
      $groups['Learning Management'][] = $link('school.cbt.take.available', 'Take Assessments', 'quiz');
    }
  }
  // Grading is its own tier: a marker needs .grade but not the admin
  // .create that every console screen below requires.
  if ($navRbac->check($navUser, $school, 'assessment_hub.grade')) {
    $groups['Learning Management'][] = $link('school.cbt.grader.index', 'Grading', 'approve');
  }
  if ($navRbac->check($navUser, $school, 'assessment_hub.create')) {
    $groups['Learning Management'][] = $link('school.cbt.assessments.index', 'Assessments', 'quiz');
    $groups['Learning Management'][] = $link('school.cbt.questions.index', 'Question Bank', 'quiz');
  }
  // The rest of this block is school-wide admin territory — every
  // staff member's assessments, live risk data, and proctoring policy,
  // not one teacher's own scoped work — so it stays on the "blanket
  // access" signal .export, not the .create/.update grant a class or
  // subject teacher holds for building their own assessments (2026-08-05
  // correction, user directives: "not all staff should have access to
  // admin backend, this is only for admin of the school to set" and
  // "teachers should not be having access to the security profiles").
  // An admin can still hand .export to another role from Role Management.
  if ($navRbac->check($navUser, $school, 'assessment_hub.export')) {
    $groups['Learning Management'][] = $link('school.cbt.console.index', 'Admin Backend', 'sliders');

    // Supervision screens only exist when proctoring is switched on
    // (see App\Support\AssessmentMode). Light mode ships the timed
    // attempt, question bank, randomisation, grading and reporting.
    if (\Illuminate\Support\Facades\Config::get('skulos.cbt_proctoring_enabled', false)) {
    $groups['Learning Management'][] = $link('school.cbt.security-profiles.index', 'Security Profiles', 'sliders');
    $groups['Learning Management'][] = $link('school.cbt.live-monitor.index', 'Proctor / Invigilator View', 'proctor');
    $groups['Learning Management'][] = $link('school.cbt.face-approvals.index', 'Face Match Approvals', 'proctor');
    $groups['Learning Management'][] = $link('school.cbt.incidents.index', 'Incidents', 'warning');
    }
  }

  // Academic Report, its own standalone entry under Academic (user
  // request 2026-07-26, then corrected 2026-07-27: "Academic report
  // should be under academics"), a parent/student never had a way to
  // reach it besides opening a child's profile first.
  if ($isFamilyScoped) {
    $groups['Academic'][] = $link('school.students.my-academic-report', 'Academic Report', 'results');
  }

  // A class/subject teacher's own queue of parent-submitted profile
  // change requests routed to their classes (user request 2026-07-26:
  // "parent should be able to edit their child details... class teacher
  // has to approve the changes first"). Corrected 2026-08-05, user
  // report: "subject teachers should not have access to student profile
  // change request as its meant for only class teachers and admin. This
  // only shows if the subject teacher is given the access" — a subject
  // teacher/plain teacher has nothing to review by design (requests only
  // ever route to a real class_teacher_staff_id), so the earlier
  // "unconditional, they'll just see an empty queue" reasoning was
  // wrong per the user's own explicit correction. Now shown only to the
  // two roles that actually manage a class, or to a subject/plain
  // teacher an admin has explicitly given the 'students' extra-access
  // override (the existing per-staff grant mechanism, Staff show page).
  $canReviewProfileChanges = $scopedRoleSlug && (
    in_array($scopedRoleSlug, config('skulos.class_review_role_slugs', []), true)
    || ($isTeacherScoped && $navRbac->hasModuleOverride($school, $navUser, 'students'))
  );
  if ($canReviewProfileChanges) {
    $groups['Academic'][] = $link('school.students.profile-change.class-teacher.index', 'Change Requests', 'inbox');
  }

  // "Enter Scores" (school.examinations.index), the real score-entry
  // screen teachers use, was previously only reachable one click away via
  // a button on the Report Cards page (2026-07-15's Results/Report Cards
  // merge), never its own nav item (user report 2026-07-18: "the
  // examination page is missing as a menu"). Not gated by any plan
  // feature, same "core functionality every plan gets" treatment as
  // "Report settings"; available to scoped teacher roles too, unlike
  // Grading below, since entering scores is exactly what a class/subject
  // teacher needs this for.
  if ($navRbac->check($navUser, $school, 'examinations.view') && ! isset($renderedRoutes['school.examinations.index'])) {
    $renderedRoutes['school.examinations.index'] = true;
    $academicSubgroups['Examinations']['enter_scores'] = $link('school.examinations.index', 'Enter Scores', 'pencil');
  }

  // Narrative Evaluation (user request 2026-07-18: "add a new settings
  // for all admins across all plan that allows the admin... to set a
  // class or some classes to have the Narrative Evaluation style of
  // report... add it under examination as a sub menu"). Not gated by
  // any plan feature, same "core functionality every plan gets"
  // treatment as Enter Scores/Report settings, and admin only (the
  // same blanket examinations.approve_results tier already used for
  // score-entry window policy), never shown to a scoped teacher role.
  if (! $isRestrictedNav && $navRbac->check($navUser, $school, 'examinations.approve_results')
      && ! isset($renderedRoutes['school.examinations.narrative-evaluation'])) {
    $renderedRoutes['school.examinations.narrative-evaluation'] = true;
    $academicSubgroups['Examinations']['narrative_evaluation'] = $link('school.examinations.narrative-evaluation', 'Narrative Evaluation', 'narrative');
  }

  // Term Calculation (user request 2026-07-24: "add a settings under
  // examination on the termly report card calculation settings...
  // admin can toggle it off if the school decide otherwise"). Same
  // admin only tier and "not gated by any plan feature" treatment as
  // Narrative Evaluation right above it.
  if (! $isRestrictedNav && $navRbac->check($navUser, $school, 'examinations.approve_results')
      && ! isset($renderedRoutes['school.examinations.report-card-calculation'])) {
    $renderedRoutes['school.examinations.report-card-calculation'] = true;
    $academicSubgroups['Examinations']['report_card_calculation'] = $link('school.examinations.report-card-calculation', 'Term Calculation', 'percent');
  }

  // Bulk Generate Report Cards (user request 2026-07-23: "add a bulk way
  // for the admin to trigger bulk generate report card for all classes...
  // should be a submenu under examination only for the school admin who
  // can decide to give it to other users"). Its own
  // report_cards.bulk_generate_all permission, not the plain
  // report_cards.create every class/subject teacher already holds for
  // their own single class, so this stays admin-tier only until a school
  // deliberately grants it further via Roles & Permissions. Also gated on
  // the report cards plan feature itself, same as "Pending review" above
  // (found via a failing test 2026-07-24: a permission grant on its own
  // is not a plan gate, a school on a plan without basic_report_cards at
  // all could still see this link since the wildcard role template holds
  // every permission regardless of which features the school's plan
  // actually includes).
  if (! $isRestrictedNav && $school?->hasFeature('basic_report_cards')
      && $navRbac->check($navUser, $school, 'report_cards.bulk_generate_all')
      && ! isset($renderedRoutes['school.report-cards.bulk-generate'])) {
    $renderedRoutes['school.report-cards.bulk-generate'] = true;
    $academicSubgroups['Examinations']['bulk_generate'] = $link('school.report-cards.bulk-generate', 'Bulk Generate Report Cards', 'copies');
  }

  // Bulk Release (user request 2026-07-26: "same way we have bulk
  // generate report cards, lets add a bulk release all report card"),
  // the same shape and gating as Bulk Generate just above, on
  // report_cards.release since that is the exact permission the action
  // itself checks.
  if (! $isRestrictedNav && $school?->hasFeature('basic_report_cards')
      && $navRbac->check($navUser, $school, 'report_cards.release')
      && ! isset($renderedRoutes['school.report-cards.bulk-release'])) {
    $renderedRoutes['school.report-cards.bulk-release'] = true;
    $academicSubgroups['Examinations']['bulk_release'] = $link('school.report-cards.bulk-release', 'Bulk Release Report Cards', 'approve');
  }

  // "Report settings" (template and field choices for the report card
  // look) is not gated by any plan feature (user decision 2026-07-11:
  // every plan should be able to reach it), so unlike everything else in
  // Reports & Analytics it is appended unconditionally rather than coming
  // from the catalog loop. Configuring the report template/look is a
  // policy decision, not something a scoped teacher role should reach
  // (user directive 2026-07-12), hidden for them even though they hold
  // report_cards.view for the Report Cards screen itself.
  if (! $isRestrictedNav && ! isset($renderedRoutes['school.report-cards.edit'])) {
    $renderedRoutes['school.report-cards.edit'] = true;
    $groups['Reports & Analytics'][] = $link('school.report-cards.edit', 'Report settings', 'sliders');
  }

  // "Pending review" is a sub screen of Report Cards, not its own catalog
  // feature, so it does not come from the loop above. The user asked
  // (2026-07-11) for it to have its own line under Reports & Analytics
  // next to "Report Cards" and "Report settings", gated on the same
  // feature that gates the report card workflow itself. It is a cross
  // school review queue for whoever is assigned reviewer duty, not
  // something a scoped teacher role browses (2026-07-12).
  if (! $isRestrictedNav && $school?->hasFeature('basic_report_cards')
      && ! isset($renderedRoutes['school.report-cards.pending-review'])) {
    $renderedRoutes['school.report-cards.pending-review'] = true;
    $groups['Reports & Analytics'][] = $link('school.report-cards.pending-review', 'Pending review', 'inbox');
  }

  // Module granted add ons (Library, Hostel, ...) are a separate system
  // from plan features (any plan can be granted any add on), so they are
  // appended to their most natural existing category group rather than
  // getting their own, reusing App\Services\Rbac\Rbac::moduleState() the
  // same way App\Http\Controllers\Admin\BillingController::grantAddOn()/
  // revokeAddOn() already read and write it. None of these are built
  // screens yet (all render as "Soon"), so a scoped teacher role never
  // sees them either (2026-07-12).
  $addOnModules = $isRestrictedNav ? [] : [
    'School Operations' => [
      'library' => ['Library', 'library'],
      'hostel' => ['Hostel', 'hostel'],
      'transport' => ['Transport', 'transport'],
      'inventory' => ['Inventory', 'inventory'],
      'medical' => ['Medical / Clinic', 'medical'],
      'visitors' => ['Visitor Management', 'visitors'],
      'discipline' => ['Discipline', 'discipline'],
    ],
  ];
  foreach ($addOnModules as $groupLabel => $modules) {
    foreach ($modules as $moduleCode => [$label, $icon]) {
      if (! $school) {
        continue;
      }
      $state = app(\App\Services\Rbac\Rbac::class)->moduleState($school, $moduleCode);
      if (in_array($state, [\App\Models\Module::STATE_DISABLED, \App\Models\Module::STATE_HIDDEN], true)) {
        continue;
      }
      $groups[$groupLabel] = array_merge($groups[$groupLabel] ?? [], [$soonLink($label, $icon)]);
    }
  }

  // Guided Setup and the school's own operational settings are not
  // customer facing plan features at all, so they are not part of the
  // catalog loop above, they always show, except to a scoped teacher role
  // (user directive 2026-07-12: "should not be seeing... settings"), none
  // of these are things a class or subject teacher configures. Sorted
  // into the same 7 nested sub-groups the catalog loop above already
  // feeds (user directive 2026-07-17, exact tree given: School / Users /
  // Communication / Appearance / Security / System / Analytics, "note
  // that all are not to appear as one but only the active one" — a
  // nested single-open accordion, see the render loop and the second
  // accordion-controller script below). Several leaf items the user named
  // (SMS, Password Policy, Two Factor Authentication, Login Logs,
  // Templates, Themes, Integrations, Performance Rules, Reports) have no
  // dedicated screen behind them yet. A first pass rendered these as
  // "Soon", the same way an unbuilt plan feature already does elsewhere
  // in this nav — but user feedback 2026-07-18 was explicit that this
  // regressed Settings specifically ("I AM STILL SEEING COMING SOON
  // UNDER SOME FEATURES... THIS IS WRONG"): every other item in this
  // section was always a real, working link, unlike the plan-feature-
  // catalog groups elsewhere that have always mixed real screens with
  // named "Soon" ones. Omitted entirely instead until a real screen
  // exists, rather than reintroducing "Soon" here. Where a named leaf
  // genuinely is the same real screen as a sibling (Parent Settings/
  // Staff Settings both live on the one Student Fields screen; Storage/
  // Health are one combined School Health & Storage screen), it points
  // at that same route under its own label rather than being invented a
  // screen it does not have, the same "shared real screen, distinct
  // labels" pattern Staff Portal/Teacher Portal already use above.
  if (! $isRestrictedNav) {
    $settingsSubgroups['School'] = array_merge($settingsSubgroups['School'], [
      $link('school.campuses', 'Campuses', 'campus'),
      // Same real screen the top-level Academic group's own "Calendar"
      // link already points at (academic_calendar, merged there
      // 2026-07-16); the user's Settings tree separately lists it here
      // too, so both stay real, just two entry points into one screen.
      $link('school.calendar.index', 'Academic Calendar', 'calendar'),
    ]);
    /*
     * All three of these are one real shared screen ("Student, Parent &
     * Staff Field Settings"), the same "shared real screen, distinct
     * labels" pattern Campuses/Health already use above, never three
     * separate pages. User report 2026-07-22: "staff and parent field
     * setting keeps going back to student field settings", because the
     * destination always looked and read like it was ONLY about
     * students regardless of which of the three labels was clicked. The
     * page itself is now titled to cover all three and the Parent/Staff
     * links jump straight to the shared identifier section by anchor
     * (via $linkWithAnchor, a thin wrapper around $link()), so landing
     * there no longer reads as a wrong destination.
     */
    $linkWithAnchor = function ($routeName, $anchor, $label, $icon) use ($link, $slug) {
      $entry = $link($routeName, $label, $icon);
      if ($slug && \Illuminate\Support\Facades\Route::has($routeName)) {
        $plainUrl = route($routeName, ['school' => $slug]);
        $entry['html'] = str_replace('href="'.$plainUrl.'"', 'href="'.$plainUrl.'#'.$anchor.'"', $entry['html']);
      }

      return $entry;
    };
    $settingsSubgroups['Users'] = array_merge($settingsSubgroups['Users'], [
      $linkWithAnchor('school.students.settings.edit', 'student-fields', 'Student Fields', 'field'),
      $linkWithAnchor('school.students.settings.edit', 'identifier-settings', 'Parent Settings', 'field'),
      $linkWithAnchor('school.students.settings.edit', 'identifier-settings', 'Staff Settings', 'field'),
      $link('school.settings.custom-fields.index', 'Custom Fields', 'field'),
    ]);
    // Previously only reachable from a small link tucked in the account
    // footer at the very bottom of the sidebar (user report 2026-07-24:
    // "a user made a profile change that requires admin review and
    // approval... seems there's no page for it as i cant find it under
    // menu, should be a sub under users in admin setting menu"). Same
    // staff.update gate the footer link and the approvals page itself
    // already use, added here too as a real, findable nav entry, not a
    // replacement for the footer shortcut.
    if ($navRbac->check($navUser, $school, 'staff.update')) {
      $settingsSubgroups['Users'][] = $link('school.profile.approvals', 'Profile Approvals', 'approve');
    }
    // Final admin approval queue for a parent's requested student
    // profile change, after the class teacher has already approved it
    // (user request 2026-07-26). Same students.manage_settings tier the
    // controller itself gates on.
    if ($navRbac->check($navUser, $school, 'students.manage_settings')) {
      $settingsSubgroups['Users'][] = $link('school.students.profile-change.admin.index', 'Student Change Requests', 'approve');
    }
    // A single admin oversight screen merging every approval flow above
    // into one list, with nudge and bulk nudge (user request 2026-07-27:
    // "admin should have an approval page where the admin can see all
    // approval request sent to admin, sent to other users, class
    // teachers, report card reviewers, and any one"). Same tier as the
    // Student Change Requests entry right above it.
    if ($navRbac->check($navUser, $school, 'students.manage_settings')) {
      $settingsSubgroups['Users'][] = $link('school.approvals.index', 'Approvals', 'approve');
    }
    $communicationSettingsLinks = [$link('school.settings.mail.edit', 'Mail Settings', 'sliders')];
    // "Notifications" here is the exact same route the email_notifications
    // catalog entry above already renders (and marks in $renderedRoutes)
    // once a school's plan includes that feature (2026-07-18's forceGroup
    // comment says as much: "or an Advance-tier school would see it
    // rendered twice"), but this unconditional block never actually
    // checked for that, so an Advance-tier school saw it twice anyway
    // (found in a 2026-07-19 UI audit). Guarded the same way every other
    // dedup in this file already is.
    if (! isset($renderedRoutes['school.settings.communication.edit'])) {
      $renderedRoutes['school.settings.communication.edit'] = true;
      $communicationSettingsLinks[] = $link('school.settings.communication.edit', 'Notifications', 'toggle');
    }
    $settingsSubgroups['Communication'] = array_merge($settingsSubgroups['Communication'], $communicationSettingsLinks);
    $settingsSubgroups['Appearance'] = array_merge($settingsSubgroups['Appearance'], [
      $link('school.dashboard.edit', 'Dashboard', 'widgets'),
      $link('school.settings.landing-pages.edit', 'Landing Pages', 'pages'),
    ]);
    $settingsSubgroups['Security'] = array_merge($settingsSubgroups['Security'], [
      $link('school.settings.idle-timeout.edit', 'Idle Timeout', 'clock'),
    ]);
    $settingsSubgroups['System'] = array_merge($settingsSubgroups['System'], [
      $link('school.backups.index', 'Backups', 'backup'),
      $link('school.settings.retention.edit', 'Retention', 'retention'),
      // Storage and Health were two separate nav lines both pointing at
      // the exact same real screen (school.maintenance.index, "School
      // Health & Storage", built 2026-07-12 as one combined screen from
      // the start), user report 2026-07-22: "storage and health should
      // be merge as one menu since its same data there." Merged into
      // one line matching the page's own title.
      $link('school.maintenance.index', 'Storage &amp; Health', 'health'),
    ]);
    $settingsSubgroups['Analytics'] = array_merge($settingsSubgroups['Analytics'], [
      $link('school.settings.at-risk-thresholds.edit', 'At Risk Thresholds', 'warning'),
    ]);
  }

  // Subscription (user request 2026-07-17): visible only to
  // config('skulos.subscription_visible_role_slugs') (Owner/Principal/
  // VP/Head Teacher/School Admin), narrower than the general
  // "! $isRestrictedNav" block above, which still includes Bursar/
  // Registrar/ICT Officer/etc. Not part of the user's originally given
  // Settings tree (it postdates that request by less than a day); placed
  // under System as the closest fit among the 7 named sub-groups, an
  // account/platform-operational matter like Backups or Retention rather
  // than anything School/Users/Communication/Appearance/Security/
  // Analytics-shaped.
  if (in_array($scopedRoleSlug, config('skulos.subscription_visible_role_slugs', []), true)) {
    $settingsSubgroups['System'][] = $link('school.subscription.index', 'Subscription', 'renew');
  }

  // "Guided Setup" itself is a first-run admin task (creating sessions,
  // class structure, houses...), not something a scoped teacher role
  // should be steered toward either.
  if ($isRestrictedNav) {
    $groups['Main'] = [$link('school.dashboard', 'Dashboard', 'dashboard')];
  }

  // A family-scoped role additionally gets a "My Children"/"My Report"
  // link (user request 2026-07-13), reusing the Students route, which
  // FamilyScope narrows down to just their own family for them, not the
  // school-wide Students screen a scoped teacher (who this Main-group
  // override is otherwise shared with) sees.
  if ($isFamilyScoped) {
    // Singular for a parent with exactly one child (user directive
    // 2026-07-28: "for parents that have one child, it should show as
    // My child and not my children"). A student's own single record
    // already says "My Report", not "My Children"/"My Reports", so only
    // the parent label needed a count check.
    $myChildrenLabel = 'My Children';
    if ($scopedRoleSlug === 'parent' && app(\App\Services\Rbac\FamilyScope::class)->studentIds($navUser, $school)?->count() === 1) {
      $myChildrenLabel = 'My Child';
    }
    $groups['Main'][] = $link('school.students', $scopedRoleSlug === 'parent' ? $myChildrenLabel : 'My Report', 'students');
  }

  // "Chat" (messaging increment 4, 2026-07-15): only the two roles that
  // actually participate in the teacher<->guardian 1-to-1 pairing get a
  // nav entry for it, a "student" role does not (see ChatService::
  // isGuardianOf()'s deliberate role-slug guard), neither does an
  // unrestricted staff role (owner/admin send broadcasts, not this).
  // Grouped under Communication, not Main (user directive 2026-08-05:
  // "let's move chat to under communication"), alongside Announcements/
  // Messages, which already land there via the 'communication'
  // plan_feature_catalog category's own label.
  if ($isTeacherScoped || $scopedRoleSlug === 'parent') {
    $groups['Communication'][] = $link('school.chat.index', 'Chat', 'chat');
  }

  // Build the Academic > Examinations nested subsection (see
  // $academicSubgroups above) into one pre-rendered flat entry, the same
  // .side-subsection/.side-subgroup-btn/.side-subsubmenu markup and JS
  // controller Settings' own sub-groups already use (fully generic, not
  // scoped to Settings specifically), pushed into the otherwise-flat
  // Academic group rather than making the whole group nested.
  $examEntries = [];
  foreach (['enter_scores', 'term_broadsheets', 'grading', 'narrative_evaluation', 'report_card_calculation', 'bulk_generate', 'bulk_release'] as $examKey) {
    if (isset($academicSubgroups['Examinations'][$examKey])) {
      $examEntries[] = $academicSubgroups['Examinations'][$examKey];
    }
  }
  if (count($examEntries)) {
    $examHasActive = collect($examEntries)->contains(fn ($e) => $e['active']);
    $examHtml = '<div class="side-subsection">'
      .'<button type="button" class="side-subgroup-btn" aria-expanded="'.($examHasActive ? 'true' : 'false').'" data-subgroup="academic-examinations">'
      .'<span>Examinations</span>'.$chev.'</button>'
      .'<div class="side-subsubmenu'.($examHasActive ? ' open' : '').'" data-subgroup-panel="academic-examinations"><div>'
      .collect($examEntries)->pluck('html')->implode('')
      .'</div></div></div>';
    $groups['Academic'][] = ['active' => $examHasActive, 'html' => $examHtml];
  }

  // Explicit group order (user directive 2026-07-15: "I WANT TO MOVE THE
  // WHOLE ADMINISTRATION SECTION TO COME IMMEDIATELY AFTER ACADEMIC
  // SECTION"), overriding whatever order the catalog categories happened
  // to iterate in above. Any group not named here keeps its existing
  // relative order, appended after these two.
  $groupOrder = ['Main', 'Academic', 'Administration'];
  $orderedGroups = [];
  foreach ($groupOrder as $name) {
    if (isset($groups[$name])) {
      $orderedGroups[$name] = $groups[$name];
      unset($groups[$name]);
    }
  }
  // "Settings" pinned last, always, for whoever has it at all (user
  // directive 2026-07-16: "I WANT THE WHOLE SETTINGS SECTION TO BE THE
  // LAST FOR EVERY USER THAT HAVE ACCESS TO IT"), appended here too so it
  // is never accidentally left ahead of a later-appearing category group
  // (Reports & Analytics, Communication, Finance, School Operations, ...)
  // the way plain insertion order alone does not guarantee. $groups
  // itself never collects a flat 'Settings' entry any more (see
  // $settingsSubgroups above, populated instead throughout this file);
  // trimmed down here to whichever of the 7 sub-groups actually ended up
  // with content, in the exact order given, and stored as the one nested
  // (sub-group => entries) value the render loop below specifically
  // detects by label to render its extra accordion level.
  $groups = $orderedGroups + $groups;
  $settingsSubgroups = array_filter($settingsSubgroups, fn ($entries) => count(array_filter($entries)) > 0);
  if (count($settingsSubgroups)) {
    $groups['Settings'] = $settingsSubgroups;
  }
@endphp

@foreach($groups as $label => $entries)
  @if($label === 'Settings')
    {{--
      Settings gets a second, nested accordion level (user directive
      2026-07-17: an exact 7-sub-group tree — School / Users /
      Communication / Appearance / Security / System / Analytics —
      "note that all are not to appear as one but only the active one",
      i.e. only one sub-group open within Settings at a time, on top of
      the existing rule that only one top-level group is open at all).
      $entries here is (subLabel => [entry, ...]), see $settingsSubgroups
      above, not a flat entry list like every other group.
    --}}
    @php
      $hasActiveSettings = collect($entries)->flatten(1)->contains(fn ($e) => is_array($e) && ($e['active'] ?? false));
    @endphp
    <div class="side-section">
      <button type="button" class="side-group-btn" aria-expanded="{{ $hasActiveSettings ? 'true' : 'false' }}" data-group="settings">
        <span>{{ $label }}</span>
        {!! $chev !!}
      </button>
      <div class="side-submenu{{ $hasActiveSettings ? ' open' : '' }}" data-group-panel="settings">
        <div>
          @foreach($entries as $subLabel => $subEntries)
            @php
              $subEntries = array_filter($subEntries);
              $subSlug = 'settings-'.\Illuminate\Support\Str::slug($subLabel);
              $hasActiveSub = collect($subEntries)->contains(fn ($e) => $e['active']);
            @endphp
            @if(count($subEntries))
              <div class="side-subsection">
                <button type="button" class="side-subgroup-btn" aria-expanded="{{ $hasActiveSub ? 'true' : 'false' }}" data-subgroup="{{ $subSlug }}">
                  <span>{{ $subLabel }}</span>
                  {!! $chev !!}
                </button>
                <div class="side-subsubmenu{{ $hasActiveSub ? ' open' : '' }}" data-subgroup-panel="{{ $subSlug }}">
                  <div>
                    @foreach($subEntries as $entry)
                      {!! $entry['html'] !!}
                    @endforeach
                  </div>
                </div>
              </div>
            @endif
          @endforeach
        </div>
      </div>
    </div>
  @else
    @php
      $entries = array_filter($entries);
      $hasActive = collect($entries)->contains(fn ($e) => $e['active']);
    @endphp
    @if(count($entries))
      <div class="side-section">
        <button type="button" class="side-group-btn" aria-expanded="{{ $hasActive ? 'true' : 'false' }}" data-group="{{ \Illuminate\Support\Str::slug($label) }}">
          <span>{{ $label }}</span>
          {!! $chev !!}
        </button>
        <div class="side-submenu{{ $hasActive ? ' open' : '' }}" data-group-panel="{{ \Illuminate\Support\Str::slug($label) }}">
          <div>
            @foreach($entries as $entry)
              {!! $entry['html'] !!}
            @endforeach
          </div>
        </div>
      </div>
    @endif
  @endif
@endforeach

<style>
  .side-toggle-all{flex-shrink:0;width:1.6rem;height:1.6rem;border-radius:6px;border:1px solid transparent;
    background:transparent;color:var(--mut);display:grid;place-items:center;cursor:pointer;padding:0}
  .side-toggle-all:hover{color:var(--ink);border-color:var(--line)}
  .side-toggle-all .chev-toggle{width:1.1rem;height:1.1rem;transition:transform .18s ease}
  .side-toggle-all.is-open .chev-toggle{transform:rotate(180deg)}

  /* Settings' nested sub-group accordion (2026-07-17), one level deeper
     than every other group's .side-group-btn/.side-submenu, indented and
     slightly smaller so it reads as a child level, not a sibling. */
  .side-subsection{margin-left:.5rem}
  .side-subgroup-btn{width:100%;background:none;border:none;cursor:pointer;display:flex;align-items:center;
    justify-content:space-between;text-align:left;padding:.5rem .65rem;border-radius:8px;
    color:var(--side-mut);font-weight:600;font-size:.82rem}
  .side-subgroup-btn:hover{background:var(--side-active);color:var(--side-ink)}
  .side-subgroup-btn .chev{width:11px;height:11px;flex-shrink:0;transition:transform .15s ease}
  .side-subgroup-btn[aria-expanded="true"] .chev{transform:rotate(90deg)}
  .side-subsubmenu{display:grid;grid-template-rows:0fr;transition:grid-template-rows .18s ease}
  .side-subsubmenu.open{grid-template-rows:1fr}
  .side-subsubmenu>div{overflow:hidden}
  .side-subsubmenu .side-link{padding-left:1.1rem;font-size:.85rem}
  /* Collapsed sidebar (icon rail): the top-level accordion already forces
     every .side-submenu open and hides its own .side-group-btn (see
     app.blade.php's .shell.collapsed rules); the nested level needs the
     same treatment so a collapsed Settings still shows every real link
     as an icon instead of stray, unstyled sub-group buttons. */
  .shell.collapsed .side-subgroup-btn{display:none}
  .shell.collapsed .side-subsubmenu{grid-template-rows:1fr}
</style>
<script>
(function(){
  var STORAGE_KEY = 'skulos-nav-groups';
  var state = {};
  try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e){}

  var pairs = []; // {btn, panel, name}
  document.querySelectorAll('.side-group-btn').forEach(function(btn){
    var name = btn.getAttribute('data-group');
    var panel = document.querySelector('.side-submenu[data-group-panel="'+name+'"]');
    if (!panel) return;
    pairs.push({btn: btn, panel: panel, name: name});
  });

  function setOpen(pair, open){
    pair.panel.classList.toggle('open', open);
    pair.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    state[pair.name] = open;
  }
  function persist(){
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e){}
  }

  // Only one group open at a time (user directive 2026-07-15: "WHEN ONE
  // IS IN A MENU SECTION, OTHER MENU OPTIONS SHOULD COLLAPSE SO ITS
  // EASIER TO SCROLL THROUGH THE MENU SECTIONS"). On load: the group
  // containing the current page's active link always wins regardless of
  // saved history; otherwise the last group saved open wins; everything
  // else starts closed, even if a stale pre-accordion save had several
  // marked open at once.
  var activePair = pairs.find(function(p){ return p.btn.getAttribute('aria-expanded') === 'true'; });
  var toOpen = activePair || pairs.slice().reverse().find(function(p){ return state[p.name] === true; }) || null;
  pairs.forEach(function(p){ setOpen(p, p === toOpen); });

  // A single arrow toggle next to the school name (user directive
  // 2026-07-15, after a first "Collapse all"/"Expand all" two-button
  // strip was rejected: "I DONT LIKE THE LOOK, JUST PUT AND ARROW AND
  // ARROW DOWN IN FROM OF THE SCHOOL NAME"), flips between opening every
  // group and closing every group, pointing down when collapsed
  // (something to reveal) and up when expanded (something to tuck away).
  var toggleAllBtn = document.getElementById('navToggleAllBtn');
  function updateToggleAllIcon(){
    if (!toggleAllBtn) return;
    var anyOpen = pairs.some(function(p){ return p.panel.classList.contains('open'); });
    toggleAllBtn.classList.toggle('is-open', anyOpen);
    toggleAllBtn.setAttribute('aria-expanded', anyOpen ? 'true' : 'false');
    toggleAllBtn.title = anyOpen ? 'Collapse all' : 'Expand all';
  }

  pairs.forEach(function(pair){
    pair.btn.addEventListener('click', function(){
      var open = !pair.panel.classList.contains('open');
      pairs.forEach(function(p){ setOpen(p, p === pair ? open : false); });
      persist();
      updateToggleAllIcon();
      // Keep the just-opened group's own header near the top of the
      // scrollable sidebar (user report, repeated: "when the menu
      // opens, the current menu tab should not go to the bottom of the
      // menu section but should be at the top"). layouts/app.blade.php
      // already fixed this exact complaint for a full PAGE LOAD landing
      // on an active link (direct .side scrollTop math, not
      // activeLink.scrollIntoView(), which does not reliably stop at
      // .side since it's position:sticky, not a plain scroll parent),
      // but that only ever runs once per page load — clicking an
      // ACCORDION GROUP HEADER never navigates anywhere, so that fix
      // never covered this specific interaction, this is a real
      // separate gap, not a duplicate of the already-fixed one.
      if (open) {
        var sideEl = document.querySelector('.side');
        if (sideEl) {
          sideEl.scrollTop = pair.btn.getBoundingClientRect().top - sideEl.getBoundingClientRect().top + sideEl.scrollTop;
        }
      }
    });
  });

  if (toggleAllBtn) {
    toggleAllBtn.addEventListener('click', function(){
      var anyOpen = pairs.some(function(p){ return p.panel.classList.contains('open'); });
      pairs.forEach(function(p){ setOpen(p, !anyOpen); });
      persist();
      updateToggleAllIcon();
    });
  }
  updateToggleAllIcon();
})();

// Second, independent accordion controller for Settings' nested
// sub-groups (2026-07-17): same single-open-at-a-time behaviour as the
// top-level one above, scoped to .side-subgroup-btn/.side-subsubmenu and
// its own localStorage key, so opening/closing a sub-group never
// interferes with which TOP-level group (Main/Academic/Settings/...) is
// open, and vice versa.
(function(){
  var STORAGE_KEY = 'skulos-nav-subgroups';
  var state = {};
  try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e){}

  var pairs = [];
  document.querySelectorAll('.side-subgroup-btn').forEach(function(btn){
    var name = btn.getAttribute('data-subgroup');
    var panel = document.querySelector('.side-subsubmenu[data-subgroup-panel="'+name+'"]');
    if (!panel) return;
    pairs.push({btn: btn, panel: panel, name: name});
  });
  if (!pairs.length) return;

  function setOpen(pair, open){
    pair.panel.classList.toggle('open', open);
    pair.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    state[pair.name] = open;
  }
  function persist(){
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e){}
  }

  var activePair = pairs.find(function(p){ return p.btn.getAttribute('aria-expanded') === 'true'; });
  var toOpen = activePair || pairs.slice().reverse().find(function(p){ return state[p.name] === true; }) || null;
  pairs.forEach(function(p){ setOpen(p, p === toOpen); });

  pairs.forEach(function(pair){
    pair.btn.addEventListener('click', function(e){
      e.stopPropagation();
      var open = !pair.panel.classList.contains('open');
      pairs.forEach(function(p){ setOpen(p, p === pair ? open : false); });
      persist();
    });
  });
})();
</script>
