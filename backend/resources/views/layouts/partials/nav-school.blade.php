@php
  $slug = $school?->slug;
  $chev = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';

  $item = function($routeName, $label, $icon, $featureKey = null) use ($cur, $slug, $school) {
    if ($featureKey && ! $school?->hasFeature($featureKey)) {
      return null; // not in this school's plan: not shown at all, not just locked.
    }
    $active = $cur === $routeName;
    $url = ($slug && \Illuminate\Support\Facades\Route::has($routeName)) ? route($routeName, ['school' => $slug]) : '#';

    return [
      'active' => $active,
      'html' => '<a class="side-link'.($active ? ' active' : '').'" href="'.$url.'">'.
        view('layouts.partials.icon', ['name' => $icon])->render().
        '<span>'.$label.'</span></a>',
    ];
  };
  $soon = function($label, $icon) {
    return [
      'active' => false,
      'html' => '<a class="side-link" href="#">'.
        view('layouts.partials.icon', ['name' => $icon])->render().
        '<span>'.$label.'</span><span class="soon">Soon</span></a>',
    ];
  };
  // A whole functional area with no screen built yet (Admissions, Finance,
  // HR, Learning/LMS, CBT Examination) still needs to "automatically
  // appear" once a school's plan includes it (user request 2026-07-11:
  // "it should show for any plan or feature given to the school from the
  // billing page"), even before there is a real page behind it, shown as
  // a category level Soon entry rather than hidden entirely like the
  // still-unbuilt items above that are not gated by any plan at all.
  $soonIfCategory = function($label, $icon, $categoryKey) use ($school, $soon) {
    return $school?->hasAnyFeatureInCategory($categoryKey) ? $soon($label, $icon) : null;
  };
  // Optional modules granted as a Billing add-on (Library, Hostel,
  // Transport, ...), independent of plan tier, any plan can be granted
  // any add-on (user request 2026-07-11: "features ticked for the school
  // or plan, is it a custom plan etc... it must check what is under
  // billing"). Reads App\Services\Rbac\Rbac::moduleState() live, the same
  // resolution Rbac::check() itself now gates permissions on, so a grant
  // or revoke from Admin\BillingController::grantAddOn()/revokeAddOn()
  // takes effect on the very next page load, nothing cached or hardcoded.
  $soonIfModule = function($label, $icon, $moduleCode) use ($school, $soon) {
    if (! $school) {
      return null;
    }
    $state = app(\App\Services\Rbac\Rbac::class)->moduleState($school, $moduleCode);

    return in_array($state, [\App\Models\Module::STATE_DISABLED, \App\Models\Module::STATE_HIDDEN], true)
      ? null
      : $soon($label, $icon);
  };

  // Grouped into submenus (user request 2026-07-11), rather than every
  // item listed flat under a plain section label. Each group auto-opens
  // on load if it contains the current page's link, and its open/closed
  // state otherwise persists per group across page loads via a small
  // script below, since this is a full server navigation app, not an
  // SPA, every click would otherwise re-collapse everything.
  $groups = [
    'Main' => [
      $item('school.dashboard', 'Dashboard', 'dashboard', 'dashboard'),
      $item('school.setup', 'Guided Setup', 'settings'),
    ],
    'People' => [
      $item('school.students', 'Students', 'students', 'student_records'),
      $soon('Staff', 'staff'),
      $soon('Parents', 'students'),
      $item('school.houses.index', 'Houses', 'school', 'school_houses'),
    ],
    'Admissions' => [
      $soonIfCategory('Admissions', 'school', 'admissions'),
    ],
    'Academics' => [
      $item('school.academics.index', 'Classes &amp; Subjects', 'academics', 'classes_arms'),
      $soon('Attendance', 'attendance'),
      $item('school.examinations.index', 'Results', 'results', 'result_management'),
      $item('school.report-cards.edit', 'Report Cards', 'results', 'basic_report_cards'),
      $soonIfCategory('CBT Examinations', 'results', 'examination'),
      $soon('Calendar', 'calendar'),
    ],
    'Learning' => [
      $soonIfCategory('Learning Management', 'library', 'learning'),
      $soonIfModule('Library', 'library', 'library'),
    ],
    'Communication' => [
      $soon('Announcements', 'comms'),
    ],
    'Finance' => [
      $soonIfCategory('Fees & Payments', 'results', 'finance'),
    ],
    'Human Resources' => [
      $soonIfCategory('HR Management', 'staff', 'human_resources'),
    ],
    'School' => [
      $item('school.campuses', 'Campuses', 'school'),
      $item('school.backups.index', 'Backups', 'settings'),
      $soonIfModule('Hostel', 'school', 'hostel'),
      $soonIfModule('Transport', 'school', 'transport'),
      $soonIfModule('Inventory', 'school', 'inventory'),
      $soonIfModule('Medical / Clinic', 'school', 'medical'),
      $soonIfModule('Visitor Management', 'school', 'visitors'),
      $soonIfModule('Discipline', 'school', 'discipline'),
    ],
    'Settings' => [
      $item('school.settings.calendar.edit', 'Sessions &amp; Terms', 'calendar', 'academic_sessions_terms'),
      $item('school.branding.edit', 'Branding', 'palette', 'school_branding'),
      $item('school.dashboard.edit', 'Dashboard look', 'settings', 'dashboard'),
      $item('school.students.settings.edit', 'Student Fields', 'students'),
      $item('school.settings.mail.edit', 'Mail Settings', 'settings'),
      $item('school.settings.idle-timeout.edit', 'Idle Timeout', 'settings'),
    ],
  ];
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

<script>
(function(){
  var STORAGE_KEY = 'skulos-nav-groups';
  var state = {};
  try { state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e){}

  document.querySelectorAll('.side-group-btn').forEach(function(btn){
    var name = btn.getAttribute('data-group');
    var panel = document.querySelector('.side-submenu[data-group-panel="'+name+'"]');
    if (!panel) return;

    // A saved "closed" only wins if this group has no active link, the
    // current page's own group always stays open regardless of history.
    if (state[name] === false && btn.getAttribute('aria-expanded') !== 'true') {
      panel.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    } else if (state[name] === true) {
      panel.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
    }

    btn.addEventListener('click', function(){
      var open = panel.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      state[name] = open;
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e){}
    });
  });
})();
</script>
