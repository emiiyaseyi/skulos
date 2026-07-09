@php
  $slug = $school?->slug;
  $item = function($routeName, $label, $icon) use ($cur, $slug) {
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
{!! $item('school.dashboard', 'Dashboard', 'dashboard') !!}

<div class="side-group">People</div>
{!! $item('school.students', 'Students', 'students') !!}
{!! $soon('Staff', 'staff') !!}
{!! $soon('Parents', 'students') !!}

<div class="side-group">Academics</div>
{!! $item('school.academics.index', 'Classes &amp; Subjects', 'academics') !!}
{!! $soon('Attendance', 'attendance') !!}
{!! $soon('Results', 'results') !!}
{!! $item('school.report-cards.edit', 'Report Cards', 'results') !!}
{!! $soon('Calendar', 'calendar') !!}

<div class="side-group">Communication</div>
{!! $soon('Announcements', 'comms') !!}

<div class="side-group">School</div>
{!! $item('school.campuses', 'Campuses', 'school') !!}

<div class="side-group">Settings</div>
{!! $item('school.branding.edit', 'Branding', 'palette') !!}
{!! $item('school.dashboard.edit', 'Dashboard look', 'settings') !!}
