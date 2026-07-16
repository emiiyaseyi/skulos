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
    'user_role_management' => ['school.roles.index', 'User & Role Management', 'roles'],
    'parent_portal' => ['school.parents.directory', 'Parent Portal', 'students'],
    'student_portal' => ['school.students', 'Student Portal', 'students'],
    'attendance_management' => ['school.attendance.index', 'Attendance', 'attendance'],
    'announcements' => ['school.announcements.index', 'Announcements', 'comms'],
    'parent_broadcast' => ['school.messages.index', 'Messages', 'comms'],
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
    'unlimited_grading_systems' => ['school.examinations.grading', 'Grading', 'results'],
    'student_promotion' => ['school.settings.calendar.edit', 'Sessions &amp; Terms', 'calendar'],
    'school_profile' => ['school.campuses', 'Campuses', 'school'],
    'school_branding' => ['school.branding.edit', 'Branding', 'palette'],
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
    'Main' => [$link('school.dashboard', 'Dashboard', 'dashboard'), $link('school.setup', 'Guided Setup', 'settings'), $link('school.assignments.index', 'Assignments', 'assignments')],
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
        $groups[$targetLabel][] = $link($routeName, $label, $icon);
      } else {
        $groups[$targetLabel][] = $soonLink($featureLabel, 'settings');
      }
    }
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

  // Guided Setup and the school's own operational settings (Backups,
  // Mail Settings, Idle Timeout, Student Field settings, Dashboard look)
  // are not customer facing plan features at all, so they are not part
  // of the catalog loop above, they always show, except to a scoped
  // teacher role (user directive 2026-07-12: "should not be seeing...
  // settings"), none of these are things a class or subject teacher
  // configures.
  if (! $isRestrictedNav) {
    // array_merge, not a plain assignment: the catalog loop above may
    // already have pushed Assessment Structure/Sessions & Terms/Branding/
    // Campuses/Houses into $groups['Settings'] via $forceGroup, a plain
    // `=` here would silently wipe those back out.
    $groups['Settings'] = array_merge($groups['Settings'] ?? [], [
      $link('school.backups.index', 'Backups', 'backup'),
      $link('school.maintenance.index', 'School Health &amp; Storage', 'health'),
      $link('school.dashboard.edit', 'Dashboard look', 'settings'),
      $link('school.settings.landing-pages.edit', 'Landing Pages', 'pages'),
      $link('school.students.settings.edit', 'Student Fields', 'students'),
      $link('school.settings.mail.edit', 'Mail Settings', 'mail'),
      $link('school.settings.idle-timeout.edit', 'Idle Timeout', 'clock'),
      $link('school.settings.retention.edit', 'Retention', 'retention'),
      $link('school.settings.communication.edit', 'Communication', 'chat'),
    ]);
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
  // LAST FOR EVERY USER THAT HAVE ACCESS TO IT"), pulled out here too so
  // it is never accidentally left ahead of a later-appearing category
  // group (Reports & Analytics, Communication, Finance, School
  // Operations, ...) the way plain insertion order alone does not
  // guarantee.
  $settingsGroup = $groups['Settings'] ?? null;
  unset($groups['Settings']);
  $groups = $orderedGroups + $groups;
  if ($settingsGroup !== null) {
    $groups['Settings'] = $settingsGroup;
  }
@endphp

@foreach($groups as $label => $entries)
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
@endforeach

<style>
  .side-toggle-all{flex-shrink:0;width:1.6rem;height:1.6rem;border-radius:6px;border:1px solid transparent;
    background:transparent;color:var(--mut);display:grid;place-items:center;cursor:pointer;padding:0}
  .side-toggle-all:hover{color:var(--ink);border-color:var(--line)}
  .side-toggle-all .chev-toggle{width:1.1rem;height:1.1rem;transition:transform .18s ease}
  .side-toggle-all.is-open .chev-toggle{transform:rotate(180deg)}
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
</script>
