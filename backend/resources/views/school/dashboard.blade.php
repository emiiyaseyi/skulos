@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'notifCount' => $feed['unread'], 'pageTitle' => 'Dashboard'])
@section('title', $school->name.' Dashboard')

@push('head')
<style>
  /* figure cards, 4 across */
  .figs{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;margin-bottom:1.1rem}
  .fig b{font-size:1.7rem;font-weight:800;font-variant-numeric:tabular-nums;display:block;line-height:1.1}
  .fig span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)}
  .fig .fic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex-shrink:0}
  .fig .fic svg{width:22px;height:22px}
  /* classic: left accent bar */
  .fig-classic{display:flex;align-items:center;gap:.8rem;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:.9rem 1.1rem;position:relative;overflow:hidden;box-shadow:var(--shadow)}
  .fig-classic::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--fc)}
  .fig-classic .fic{background:color-mix(in srgb,var(--fc) 16%,transparent);color:var(--fc)}
  /* School Administration rebrand (Design Lookbook v4, corrected
     2026-07-14 against direct feedback on the live rebuild: the
     multi-colour icon chip + accent bar was carried over from the old
     design and does not match the lookbook's actual "data-fig" card,
     which is quiet: a plain bordered card, one small monochrome outline
     icon tucked in the corner at low opacity, no per-item colour, no top
     bar). Scoped to .school-theme so the platform admin portal keeps its
     original left-aligned, per-item-coloured row treatment untouched. */
  .school-theme .fig-classic{flex-direction:column;text-align:center;gap:.3rem;padding:2.5rem 1rem 1rem;background:var(--panel);border:1px solid var(--line);box-shadow:none}
  .school-theme .fig-classic::before{display:none}
  .school-theme .fig-classic .fic{position:absolute;top:.75rem;right:.75rem;width:1.9rem;height:1.9rem;background:none;color:var(--warn);opacity:.6;margin:0}
  .school-theme .fig-classic .fic svg{width:1.9rem;height:1.9rem}
  .school-theme .fig b{font-family:var(--font-serif);font-size:1.7rem}
  .school-theme .fig span{font-size:.7rem}
  /* "let's only have two rows and a button to view more": rows past the
     first 8 (2 complete rows of 4, the grid rule's own "resolve to
     complete rows" applied to how many are shown at once) start hidden;
     the toggle button below reveals the rest without ever leaving a
     dangling partial row in either state. */
  .school-theme .fig-extra{display:none}
  .school-theme .figs.show-more .fig-extra{display:revert}
  /* erp: solid colour tile */
  .fig-erp{display:flex;align-items:center;gap:1rem;border-radius:var(--radius);padding:1.3rem;color:#fff;background:var(--fc);box-shadow:var(--shadow)}
  .fig-erp span{color:rgba(255,255,255,.85)}
  .fig-erp .fic{background:rgba(255,255,255,.2);color:#fff}
  /* modern: ringed pill */
  .fig-modern{display:flex;align-items:center;gap:.7rem;background:var(--panel);border:1px solid var(--line);border-radius:999px;padding:.7rem 1.1rem;box-shadow:var(--shadow)}
  .fig-modern .ring{width:48px;height:48px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;
    background:conic-gradient(var(--fc) var(--pct,70%), color-mix(in srgb,var(--fc) 15%,transparent) 0)}
  .fig-modern .ring i{width:36px;height:36px;border-radius:50%;background:var(--panel);display:grid;place-items:center;color:var(--fc)}
  .fig-modern .ring i svg{width:18px;height:18px}
  /* vibrant: rounded colour-tint tile */
  .fig-vibrant{border-radius:18px;padding:1.3rem;background:color-mix(in srgb,var(--fc) 14%,var(--panel));border:1px solid color-mix(in srgb,var(--fc) 30%,transparent)}
  .fig-vibrant .fic{background:var(--fc);color:#fff;margin-bottom:.7rem}
  .fig-vibrant b{color:var(--ink)}

  .banner-row{display:flex;gap:1rem;margin-bottom:1.1rem;flex-wrap:wrap}
  .banner{flex:1 1 60%;min-width:260px;border-radius:16px;padding:1.2rem 1.4rem;color:#fff;position:relative;overflow:hidden;
    background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 55%,#10121a))}
  .banner h2{font-size:1.25rem;font-weight:800;margin-bottom:.2rem}
  .banner p{opacity:.9;max-width:46ch;font-size:.9rem}
  .banner .bl{position:absolute;border-radius:50%;background:rgba(255,255,255,.12)}
  .banner .bl1{width:160px;height:160px;right:-40px;top:-60px}.banner .bl2{width:100px;height:100px;right:100px;bottom:-50px;background:rgba(255,255,255,.08)}
  .ann-card{flex:1 1 32%;min-width:220px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1rem 1.2rem;box-shadow:var(--shadow);margin-bottom:1.1rem}
  .banner-row .ann-card{margin-bottom:0}
  .ann-card h3{font-size:.85rem;margin-bottom:.5rem}
  .ann-list{list-style:none;display:flex;flex-direction:column;gap:.5rem;max-height:120px;overflow-y:auto}
  .ann-list li{font-size:.82rem;padding-bottom:.5rem;border-bottom:1px solid var(--line)}
  .ann-list li:last-child{border-bottom:none;padding-bottom:0}
  .ann-list .when{color:var(--mut-dim);font-size:.72rem}

  @media(max-width:1000px){.figs{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:560px){.figs{grid-template-columns:1fr}}
</style>
@endpush

@php
  $show = fn($w) => ($widgets[$w] ?? false);
  // Figures are role specific: an owner/admin sees school wide counts, a
  // class teacher sees their own class, a subject teacher their own
  // subjects, and so on. Built by App\Services\Dashboard\RoleDashboard.
  $figs = $role['figs'];
@endphp

@section('content')

@unless($setupComplete || ! app(\App\Services\Rbac\Rbac::class)->check(auth()->user(), $school, 'academics.view'))
  <div class="card" style="margin-bottom:1.1rem;border-color:var(--accent)">
    <div class="row-between" style="flex-wrap:wrap;gap:.6rem">
      <p style="margin:0">Your school still has some one time setup left, sessions and terms, class structure, houses, or assessment structure.</p>
      <a class="btn btn-primary btn-sm" href="{{ route('school.setup', ['school' => $school->slug]) }}">Finish setup</a>
    </div>
  </div>
@endunless

@php
  $announcementsCard = view('school.widgets.announcements', ['announcements' => $announcements])->render();
@endphp

@if($layout === 'vibrant' || $layout === 'modern')
  @if($show('greeting'))
  <div class="banner-row">
    <div class="banner">
      <span class="bl bl1"></span><span class="bl bl2"></span>
      <div style="position:relative">
        <h2>Hello, {{ \Illuminate\Support\Str::before(auth()->user()->name,' ') ?: auth()->user()->name }}.</h2>
        <p>Welcome to {{ $school->name }}. Here is a snapshot of your school today.</p>
      </div>
    </div>
    {!! $announcementsCard !!}
  </div>
  @endif
@else
  @if($show('greeting'))
  <div class="page-head">
    <div><h1 class="title">{{ $school->name }}</h1><p class="subtitle">Welcome back, {{ auth()->user()->name }}.</p></div>
    <div class="stack" style="flex-direction:row;gap:.5rem">
      <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard.edit', ['school' => $school->slug]) }}">Dashboard look</a>
      @if(app(\App\Services\Rbac\Rbac::class)->check(auth()->user(), $school, 'school_info.update'))
        <a class="btn btn-ghost btn-sm" href="{{ route('school.branding.edit', ['school' => $school->slug]) }}">Branding</a>
      @endif
    </div>
  </div>
  @endif
  {!! $announcementsCard !!}
@endif

{{--
  Quick actions (Dashboard Design Cross-Check, 2026-07-13: "Add Staff /
  Register Student / Publish Results / Assign Class, Partial or Blocked,
  real screens, just not linked as a one-click action from this
  dashboard"). Shown only for the admin-tier and registrar roles those
  cross-check rows named, each button only if the viewer actually holds
  the permission it needs, same as every other permission gated link in
  the product. "Assign Class" has no standalone screen (done from a
  student's own edit page today), so it links to Students, the real entry
  point, rather than inventing a screen that does not exist.
--}}
@php
  $qaRbac = app(\App\Services\Rbac\Rbac::class);
  $showQuickActions = in_array($role['kind'] ?? '', ['admin', 'school_admin', 'registrar'], true);
@endphp
@if($showQuickActions)
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.6rem">Quick actions</h3>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      @if($qaRbac->check(auth()->user(), $school, 'staff.create'))
        <a class="btn btn-primary btn-sm" href="{{ route('school.staff.create', ['school' => $school->slug]) }}">Add staff</a>
      @endif
      @if($qaRbac->check(auth()->user(), $school, 'students.create'))
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Register student</a>
      @endif
      @if($qaRbac->check(auth()->user(), $school, 'students.update'))
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">Assign class</a>
      @endif
      @if($qaRbac->check(auth()->user(), $school, 'report_cards.assign_review') || $qaRbac->check(auth()->user(), $school, 'report_cards.release'))
        <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.pending-review', ['school' => $school->slug]) }}">Publish results</a>
      @endif
      @if($qaRbac->check(auth()->user(), $school, 'communication.broadcast'))
        <a class="btn btn-ghost btn-sm" href="{{ route('school.announcements.create', ['school' => $school->slug]) }}">Send announcement</a>
      @endif
      @if($qaRbac->check(auth()->user(), $school, 'calendar.create'))
        <a class="btn btn-ghost btn-sm" href="{{ route('school.calendar.create', ['school' => $school->slug]) }}">Create event</a>
      @endif
    </div>
  </div>
@endif

@if($show('stats') && count($figs))
<div class="figs" id="figsGrid">
  @foreach($figs as $i => $f)
    @php $figExtraClass = $i >= 8 ? ' fig-extra' : ''; @endphp
    @switch($layout)
      @case('erp')
        <div class="fig fig-erp{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}"><span class="fic">@include('layouts.partials.icon',['name'=>$f['ic']])</span><span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></span></div>
        @break
      @case('modern')
        <div class="fig fig-modern{{ $figExtraClass }}" style="--fc:{{ $f['c'] }};--pct:{{ $f['pct'] }}"><span class="ring"><i>@include('layouts.partials.icon',['name'=>$f['ic']])</i></span><span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></span></div>
        @break
      @case('vibrant')
        <div class="fig fig-vibrant{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}"><span class="fic">@include('layouts.partials.icon',['name'=>$f['ic']])</span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></div>
        @break
      @default
        <div class="fig fig-classic{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}"><span class="fic">@include('layouts.partials.icon',['name'=>$f['ic']])</span><span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></span></div>
    @endswitch
  @endforeach
</div>
@if(count($figs) > 8)
  <div style="text-align:center;margin:-.3rem 0 1.1rem">
    <button type="button" class="btn btn-ghost btn-sm" id="figsMoreToggle" data-more-label="View more" data-less-label="View less">View more</button>
  </div>
@endif
@endif

@if($role['panel'])
  @include('school.widgets.role-panel', ['panel' => $role['panel']])
@endif

@php
  $notes = view('school.widgets.notifications', ['feed' => $feed, 'school' => $school])->render();
  $cal = view('school.widgets.calendar', ['upcoming' => $upcoming])->render();
@endphp

<div class="grid cols-2">
  @if($show('notifications')){!! $notes !!}@endif
  @if($show('calendar')){!! $cal !!}@endif
</div>
@endsection

@push('scripts')
<script>
document.getElementById('figsMoreToggle')?.addEventListener('click', function(){
  var grid = document.getElementById('figsGrid');
  var open = grid.classList.toggle('show-more');
  this.textContent = open ? this.dataset.lessLabel : this.dataset.moreLabel;
});
</script>
@endpush
