@php
  $slug = $school?->slug;
  $chev = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>';

  $link = function($routeName, $label, $icon) use ($cur, $slug) {
    $active = $cur === $routeName;
    $url = ($slug && \Illuminate\Support\Facades\Route::has($routeName)) ? route($routeName, ['school' => $slug]) : '#';

    return [
      'active' => $active,
      'html' => '<a class="side-link'.($active ? ' active' : '').'" href="'.$url.'">'.
        view('layouts.partials.icon', ['name' => $icon])->render().
        '<span>'.$label.'</span></a>',
    ];
  };
  $soonLink = function($label, $icon) {
    return [
      'active' => false,
      'html' => '<a class="side-link" href="#">'.
        view('layouts.partials.icon', ['name' => $icon])->render().
        '<span>'.$label.'</span><span class="soon">Soon</span></a>',
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
    'student_records' => ['school.students', 'Students', 'students'],
    'school_houses' => ['school.houses.index', 'Houses', 'school'],
    'school_sections' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'classes_arms' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'subject_management' => ['school.academics.index', 'Classes &amp; Subjects', 'academics'],
    'academic_sessions_terms' => ['school.settings.calendar.edit', 'Sessions &amp; Terms', 'calendar'],
    'continuous_assessment' => ['school.examinations.structure', 'Assessment Structure', 'results'],
    'result_management' => ['school.examinations.index', 'Results', 'results'],
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
  $forceGroup = [
    'basic_report_cards' => 'Reports & Analytics',
  ];

  // Dashboard stays pinned in Main regardless of which catalog category
  // it belongs to, it is the app's home page, not really a "feature" in
  // the marketing sense, worth keeping prominent rather than buried
  // inside a collapsed Administration submenu.
  $renderedRoutes = ['school.dashboard' => true];
  $groups = [
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
      $targetLabel = $forceGroup[$featureKey] ?? $category['label'];
      if (isset($builtRoutes[$featureKey])) {
        [$routeName, $label, $icon] = $builtRoutes[$featureKey];
        if (isset($renderedRoutes[$routeName])) {
          continue; // several feature keys can point at the same already built screen
        }
        $renderedRoutes[$routeName] = true;
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
  // from the catalog loop.
  if (! isset($renderedRoutes['school.report-cards.edit'])) {
    $renderedRoutes['school.report-cards.edit'] = true;
    $groups['Reports & Analytics'][] = $link('school.report-cards.edit', 'Report settings', 'results');
  }

  // "Pending review" is a sub screen of Report Cards, not its own catalog
  // feature, so it does not come from the loop above. The user asked
  // (2026-07-11) for it to have its own line under Reports & Analytics
  // next to "Report Cards" and "Report settings", gated on the same
  // feature that gates the report card workflow itself.
  if ($school?->hasFeature('basic_report_cards')
      && ! isset($renderedRoutes['school.report-cards.pending-review'])) {
    $renderedRoutes['school.report-cards.pending-review'] = true;
    $groups['Reports & Analytics'][] = $link('school.report-cards.pending-review', 'Pending review', 'results');
  }

  // Module granted add ons (Library, Hostel, ...) are a separate system
  // from plan features (any plan can be granted any add on), so they are
  // appended to their most natural existing category group rather than
  // getting their own, reusing App\Services\Rbac\Rbac::moduleState() the
  // same way App\Http\Controllers\Admin\BillingController::grantAddOn()/
  // revokeAddOn() already read and write it.
  $addOnModules = [
    'School Operations' => ['library' => 'Library', 'hostel' => 'Hostel', 'transport' => 'Transport', 'inventory' => 'Inventory', 'medical' => 'Medical / Clinic', 'visitors' => 'Visitor Management', 'discipline' => 'Discipline'],
  ];
  foreach ($addOnModules as $groupLabel => $modules) {
    foreach ($modules as $moduleCode => $label) {
      if (! $school) {
        continue;
      }
      $state = app(\App\Services\Rbac\Rbac::class)->moduleState($school, $moduleCode);
      if (in_array($state, [\App\Models\Module::STATE_DISABLED, \App\Models\Module::STATE_HIDDEN], true)) {
        continue;
      }
      $groups[$groupLabel] = array_merge($groups[$groupLabel] ?? [], [$soonLink($label, 'school')]);
    }
  }

  // Guided Setup and the school's own operational settings (Backups,
  // Mail Settings, Idle Timeout, Student Field settings, Dashboard look)
  // are not customer facing plan features at all, so they are not part
  // of the catalog loop above, they always show.
  $groups['Settings'] = [
    $link('school.backups.index', 'Backups', 'settings'),
    $link('school.dashboard.edit', 'Dashboard look', 'settings'),
    $link('school.students.settings.edit', 'Student Fields', 'students'),
    $link('school.settings.mail.edit', 'Mail Settings', 'settings'),
    $link('school.settings.idle-timeout.edit', 'Idle Timeout', 'settings'),
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
