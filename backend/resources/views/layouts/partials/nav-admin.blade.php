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
{!! $item('admin.trial-requests.index', 'Trial Requests', 'trial', $pendingTrialCount ?? 0) !!}
{!! $item('admin.billing.index', 'Billing', 'billing') !!}
{!! $item('admin.subscriptions.index', 'Subscriptions', 'billing', $pendingSubscriptionPayments ?? 0) !!}
{!! $item('admin.schools.archive-list', 'Archive', 'archive') !!}
{!! $item('admin.modules.index', 'Modules', 'settings') !!}
{!! $item('admin.backups.index', 'Backups', 'retention') !!}
{!! $item('admin.broadcast.create', 'Broadcast', 'chat') !!}
{!! $item('admin.audit-log.index', 'Audit Log', 'search') !!}
{!! $item('admin.team.index', 'Platform Team', 'roles') !!}

<div class="side-group">Settings</div>
{!! $item('admin.settings.index', 'Platform Settings', 'settings') !!}
