@php
  $item = function($routeName, $label, $icon) use ($cur) {
    $active = $cur === $routeName;
    $url = \Illuminate\Support\Facades\Route::has($routeName) ? route($routeName) : '#';
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

<div class="side-group">Platform</div>
{!! $item('admin.dashboard', 'Dashboard', 'dashboard') !!}
{!! $item('admin.schools.index', 'Schools', 'school') !!}
{!! $item('admin.trial-requests.index', 'Trial Requests', 'comms') !!}
{!! $item('admin.schools.archive-list', 'Archive', 'library') !!}

<div class="side-group">Coming soon</div>
{!! $soon('Billing', 'results') !!}
{!! $soon('Platform settings', 'settings') !!}
