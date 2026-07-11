@php
  $item = function($routeName, $label, $icon, $badge = null) use ($cur) {
    $active = $cur === $routeName;
    $url = \Illuminate\Support\Facades\Route::has($routeName) ? route($routeName) : '#';
    $badgeHtml = $badge ? '<span class="badge badge-warn" style="margin-left:auto">'.$badge.'</span>' : '';
    return '<a class="side-link'.($active ? ' active' : '').'" href="'.$url.'">'.
           view('layouts.partials.icon', ['name' => $icon])->render().
           '<span>'.$label.'</span>'.$badgeHtml.'</a>';
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
{!! $item('admin.trial-requests.index', 'Trial Requests', 'comms', $pendingTrialCount ?? 0) !!}
{!! $item('admin.billing.index', 'Billing', 'results') !!}
{!! $item('admin.schools.archive-list', 'Archive', 'library') !!}

<div class="side-group">Settings</div>
{!! $item('admin.settings.mail.edit', 'Mail Settings', 'settings') !!}
{!! $item('admin.settings.idle-timeout.edit', 'Idle Timeout', 'settings') !!}
