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
   * "support" is excluded entirely: its features (Standard Support,
   * Priority Email Support, Knowledge Base, ...) are service
   * commitments, not screens a user navigates to, listing them as
   * "Soon" would misleadingly imply an unbuilt page is coming.
   */
  $builtRoutes = [
    // feature key => [routeName, label, icon]
    'dashboard' => ['school.dashboard', 'Dashboard', 'dashboard'],
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
    // Distinct from the two above: a real, dedicated CSV upload screen
    // already exists (StudentController::import(), the "Bulk Import"
    // feature's actual real capability), not the same page as the plain
    // Students browse screen.
    'bulk_import' => ['school.students.import', 'Import Students', 'students'],
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
    'parent_portal' => ['school.parents.directory', 'Parent Portal', 'students'],
    'student_portal' => ['school.students', 'Student Portal', 'students'],
    'attendance_management' => ['school.attendance.index', 'Attendance', 'attendance'],
    'attendance_dashboard' => ['school.attendance.dashboard', 'Attendance Dashboard', 'results'],
    // Same real screen as attendance_dashboard above (2026-07-18): a real
    // per-class/per-student attendance breakdown already exists there,
    // "Attendance Reports" is not a separate, distinct capability.
    'attendance_reports' => ['school.attendance.dashboard', 'Attendance Dashboard', 'results'],
    'student_performance_dashboard' => ['school.examinations.performance-dashboard', 'Student Performance Dashboard', 'results'],
    // Same real screen as student_performance_dashboard above (2026-07-18):
    // it already IS a school-wide analytics dashboard (score distributions,
    // at-risk students, subject/class breakdowns), the catalog's "Basic"
    // vs "Student Performance" naming describes the same one built screen
    // at two different plan tiers, not two different screens.
    'basic_analytics_dashboard' => ['school.examinations.performance-dashboard', 'Student Performance Dashboard', 'results'],
    // Fully built (NotificationController: categories, search, pin,
    // archive, bulk actions) since 2026-07-12 but never wired into this
    // catalog, so it only ever showed as "Soon" despite already working
    // (user directive 2026-07-16: build out the remaining Reports &
    // Analytics/Communication "Soon" items).
    'in_app_notifications' => ['school.notifications.index', 'Notifications', 'comms'],
    'announcements' => ['school.announcements.index', 'Announcements', 'comms'],
    'parent_broadcast' => ['school.messages.index', 'Messages', 'comms'],
    'communication_history' => ['school.announcements.history', 'Communication History', 'comms'],
    // Same real screen as in_app_notifications above (2026-07-18, user
    // report: "I AM STILL SEEING COMING SOON UNDER SOME FEATURES"): the
    // scheduled skulos:notify-birthdays-anniversaries command (built
    // 2026-07-16) already creates real Notification rows the moment a
    // birthday/anniversary actually falls today, they show up right here,
    // there is no separate "Birthday Notifications" screen because there
    // is no separate destination for what this feature produces.
    'birthday_anniversary_notifications' => ['school.notifications.index', 'Notifications', 'comms'],
    'school_houses' => ['school.houses.index', 'Houses', 'school'],
    'school_sections' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'classes_arms' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'subject_management' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'academic_sessions_terms' => ['school.settings.calendar.edit', 'Sessions &amp; Terms', 'calendar'],
    'continuous_assessment' => ['school.examinations.structure', 'Assessment Structure', 'results'],
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
    'student_reports' => ['school.reports.students.index', 'Student Reports', 'results'],
    'unlimited_grading_systems' => ['school.examinations.grading', 'Grading', 'results'],
    'student_promotion' => ['school.settings.calendar.edit', 'Sessions &amp; Terms', 'calendar'],
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
    'basic_fee_records' => ['school.finance.fee-items.index', 'Fee Records', 'billing'],
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
  ];
  // Populated by both the catalog loop and the unconditional block below,
  // in the exact order the user specified. Filtered down to whichever
  // sub-groups actually end up with content right before rendering.
  $settingsSubgroups = [
    'School' => [], 'Users' => [], 'Communication' => [], 'Appearance' => [],
    'Security' => [], 'System' => [], 'Analytics' => [],
  ];

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
    'Main' => [$link('school.dashboard', 'Dashboard', 'dashboard'), $link('school.setup', 'Guided Setup', 'settings')],
  ];

  foreach (config('skulos.plan_feature_catalog') as $categoryKey => $category) {
    if ($categoryKey === 'support' || ! $school?->hasAnyFeatureInCategory($categoryKey)) {
      continue;
    }

    foreach ($category['features'] as $featureKey => $featureLabel) {
      if (! $school->hasFeature($featureKey)) {
        continue;
      }
      if ($isRestrictedNav) {
        if (in_array($featureKey, $alwaysHiddenForScoped, true)) {
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
      // a flat Settings list (see $settingsSubgroups above), everything
      // else keeps the existing flat single-level group behaviour.
      if ($targetLabel === 'Settings' && isset($forceSubgroup[$featureKey])) {
        $settingsSubgroups[$forceSubgroup[$featureKey]][] = $entry;
      } else {
        $groups[$targetLabel][] = $entry;
      }
    }
  }

  // Assignments (user directive 2026-07-16: "ASSIGNMENT MENU SHOULD BE
  // MOVED TO ACADEMIC SECTION WHICH ONLY CLASS TEACHER, SUBJECT TEACHER,
  // PARENT AND STUDENT CAN SEE WITH THEIR RESPECTIVE ACCESS RIGHTS. ADMIN
  // CAN SEE ALL ACTIVE ASSIGNMENTS..."). Deliberately not routed through
  // the plan-feature catalog loop above (unlike everything else that
  // moved into Academic): 'assignments' is not part of the Foundation or
  // Advance launch plans' feature list, so gating it there would silently
  // remove an already-built, already-visible screen from every real
  // school on those tiers, a pricing decision this nav change is not the
  // place to make. Checked by real RBAC permission instead, so it shows
  // for a restricted teacher/parent/student role exactly the same way it
  // shows for an unrestricted admin one, each still only ever sees their
  // own scoped assignments once they get to the actual screen (see
  // AssignmentController::index()'s TeacherScope/FamilyScope filtering).
  if ($navRbac->check($navUser, $school, 'assignments.view') && ! isset($renderedRoutes['school.assignments.index'])) {
    $renderedRoutes['school.assignments.index'] = true;
    $groups['Academic'][] = $link('school.assignments.index', 'Assignments', 'assignments');
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
    $groups['Reports & Analytics'][] = $link('school.report-cards.edit', 'Report settings', 'results');
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
    $groups['Reports & Analytics'][] = $link('school.report-cards.pending-review', 'Pending review', 'results');
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
      $link('school.campuses', 'Campuses', 'school'),
      // Same real screen the top-level Academic group's own "Calendar"
      // link already points at (academic_calendar, merged there
      // 2026-07-16); the user's Settings tree separately lists it here
      // too, so both stay real, just two entry points into one screen.
      $link('school.calendar.index', 'Academic Calendar', 'calendar'),
    ]);
    $settingsSubgroups['Users'] = array_merge($settingsSubgroups['Users'], [
      $link('school.students.settings.edit', 'Student Fields', 'students'),
      $link('school.students.settings.edit', 'Parent Settings', 'students'),
      $link('school.students.settings.edit', 'Staff Settings', 'staff'),
    ]);
    $settingsSubgroups['Communication'] = array_merge($settingsSubgroups['Communication'], [
      $link('school.settings.mail.edit', 'Mail Settings', 'mail'),
      $link('school.settings.communication.edit', 'Notifications', 'chat'),
    ]);
    $settingsSubgroups['Appearance'] = array_merge($settingsSubgroups['Appearance'], [
      $link('school.dashboard.edit', 'Dashboard', 'settings'),
      $link('school.settings.landing-pages.edit', 'Landing Pages', 'pages'),
    ]);
    $settingsSubgroups['Security'] = array_merge($settingsSubgroups['Security'], [
      $link('school.settings.idle-timeout.edit', 'Idle Timeout', 'clock'),
    ]);
    $settingsSubgroups['System'] = array_merge($settingsSubgroups['System'], [
      $link('school.backups.index', 'Backups', 'backup'),
      $link('school.settings.retention.edit', 'Retention', 'retention'),
      $link('school.maintenance.index', 'Storage', 'health'),
      $link('school.maintenance.index', 'Health', 'health'),
    ]);
    $settingsSubgroups['Analytics'] = array_merge($settingsSubgroups['Analytics'], [
      $link('school.settings.at-risk-thresholds.edit', 'At Risk Thresholds', 'results'),
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
    $settingsSubgroups['System'][] = $link('school.subscription.index', 'Subscription', 'billing');
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
    $groups['Main'][] = $link('school.students', $scopedRoleSlug === 'parent' ? 'My Children' : 'My Report', 'students');
  }

  // "Chat" (messaging increment 4, 2026-07-15): only the two roles that
  // actually participate in the teacher<->guardian 1-to-1 pairing get a
  // nav entry for it, a "student" role does not (see ChatService::
  // isGuardianOf()'s deliberate role-slug guard), neither does an
  // unrestricted staff role (owner/admin send broadcasts, not this).
  if ($isTeacherScoped || $scopedRoleSlug === 'parent') {
    $groups['Main'][] = $link('school.chat.index', 'Chat', 'chat');
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
