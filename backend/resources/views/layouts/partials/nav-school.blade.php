@php
  $slug = $school?->slug;
  $item = function($routeName, $label, $icon, $featureKey = null) use ($cur, $slug, $school) {
    if ($featureKey && ! $school?->hasFeature($featureKey)) {
      return ''; // not in this school's plan: not shown at all, not just locked.
    }
    $active = $cur === $routeName;
    $url = ($slug && \Illuminate\Support\Facades\Route::has($routeName)) ? route($routeName, ['school' => $slug]) : '#';
    return '<a class="side-link'.($active ? ' active' : '').'" href="'.$url.'">'.
           view('layouts.partials.icon', ['name' => $icon])->render().
           '<span>'.$label.'</span></a>';
  };
  $soon = function($label, $icon) {
    return '<a class="side-link" href="#">'.
           view('layouts.partials.icon', ['name' => $icon])->render().
           '<span>'.$label.'</span><span class="soon">Soon</span></a>';
  };
@endphp

<div class="side-group">Main</div>
{!! $item('school.dashboard', 'Dashboard', 'dashboard', 'dashboard') !!}
{!! $item('school.setup', 'Guided Setup', 'settings') !!}

<div class="side-group">People</div>
{!! $item('school.students', 'Students', 'students', 'student_records') !!}
{!! $soon('Staff', 'staff') !!}
{!! $soon('Parents', 'students') !!}
{!! $item('school.houses.index', 'Houses', 'school', 'school_houses') !!}

<div class="side-group">Academics</div>
{!! $item('school.academics.index', 'Classes &amp; Subjects', 'academics', 'classes_arms') !!}
{!! $soon('Attendance', 'attendance') !!}
{!! $item('school.examinations.index', 'Results', 'results', 'result_management') !!}
{!! $item('school.report-cards.edit', 'Report Cards', 'results', 'basic_report_cards') !!}
{!! $soon('Calendar', 'calendar') !!}

<div class="side-group">Communication</div>
{!! $soon('Announcements', 'comms') !!}

<div class="side-group">School</div>
{!! $item('school.campuses', 'Campuses', 'school') !!}
{!! $item('school.backups.index', 'Backups', 'settings') !!}

<div class="side-group">Settings</div>
{!! $item('school.settings.calendar.edit', 'Sessions &amp; Terms', 'calendar', 'academic_sessions_terms') !!}
{!! $item('school.branding.edit', 'Branding', 'palette', 'school_branding') !!}
{!! $item('school.dashboard.edit', 'Dashboard look', 'settings', 'dashboard') !!}
{!! $item('school.students.settings.edit', 'Student Fields', 'students') !!}
{!! $item('school.settings.mail.edit', 'Mail Settings', 'settings') !!}
{!! $item('school.settings.idle-timeout.edit', 'Idle Timeout', 'settings') !!}
