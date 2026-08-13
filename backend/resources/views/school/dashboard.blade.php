@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Dashboard'])
@section('title', $school->name.' Dashboard')

@push('head')
<style>
  /* figure cards, 4 across */
  .figs{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;margin-bottom:1.1rem}
  {{-- min-width:0 (UX audit finding, 2026-08-10): a grid item's default
       min-width is its content's own unbroken min-content size, not 0 —
       for a stat whose value is text rather than a short number (a
       student's own class name, e.g. "Creche/Reception"), that let the
       text overflow its card at desktop's 4-column width instead of
       wrapping, clipping both ends ("Reche/Receptio"). Mobile's single
       column happened to be wide enough not to trigger it, which is why
       it only ever showed up on desktop. --}}
  .fig{position:relative;min-width:0}
  .fig-link{position:absolute;inset:0;z-index:1;border-radius:inherit}
  .fig:has(.fig-link):hover{box-shadow:0 2px 10px -2px color-mix(in srgb,var(--fc,var(--accent)) 45%,transparent)}
  {{-- Same "big stat number" size as the shared .stat card (layouts/app.blade.php), not a third invented size, per the 2026-07-28 text consistency audit. --}}
  .fig b{font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums;display:block;line-height:1.1;color:var(--ink);overflow-wrap:anywhere}
  .fig span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut)}
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
  .school-theme .fig b{font-size:1.9rem}
  /* User directive 2026-07-16: "instead of first two rows, make it one
     row and the show more button" (was 2 rows of 4 = 8). Rows past the
     first 4 (1 complete row, the grid rule's own "resolve to complete
     rows" applied to how many are shown at once) start hidden; the
     toggle button below reveals the rest without ever leaving a dangling
     partial row in either state. */
  /* `display:none` to hide, then `display:revert` on reveal, used to sit
     here, but `revert` resets to the browser's own UA-stylesheet default
     for the element (`block` for a plain <div>), not back to whichever
     specific display value that card's own layout rule below actually
     wants (`flex` for erp/modern/classic, implicit block for vibrant).
     Every card past the first row would silently lose its flex layout
     the moment "Show more" revealed it, exactly matching a user report
     (2026-07-16, screenshot comparing the first row against the rest):
     row one (never .fig-extra) stayed a compact icon-beside-number row,
     every later row (revealed via .fig-extra, hit the revert bug)
     stacked icon/number/label vertically like block content instead.
     Scoping the hide rule to :not(.show-more) instead removes the need
     to revert anything at all: once .show-more is present this selector
     simply no longer matches, so the card's own real display rule below
     is never overridden in the first place. */
  .school-theme .figs:not(.show-more) .fig-extra{display:none}
  /* erp: solid colour tile */
  .fig-erp{display:flex;align-items:center;gap:1rem;border-radius:var(--radius);padding:1.3rem;color:#fff;background:var(--fc);box-shadow:var(--shadow)}
  .fig-erp span{color:rgba(255,255,255,.85)}
  .fig-erp .fic{background:rgba(255,255,255,.2);color:#fff}
  /* modern: ringed pill. The icon ring used to be a partial arc driven by
     a fixed, made up progress amount with no real meaning for a raw
     headcount or list total (user report 2026-07-16, screenshot:
     "poorly design with many errors"), exactly the kind of fabricated
     data visual the project's standing "no dummy data" rule forbids.
     Replaced with a plain tinted circle, still colour coded per card,
     no invented progress amount. */
  .fig-modern{display:flex;align-items:center;gap:.7rem;background:var(--panel);border:1px solid var(--line);border-radius:999px;padding:.7rem 1.1rem;box-shadow:var(--shadow)}
  .fig-modern .ring{width:48px;height:48px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;background:color-mix(in srgb,var(--fc) 16%,transparent)}
  .fig-modern .ring i{color:var(--fc);display:grid;place-items:center}
  .fig-modern .ring i svg{width:22px;height:22px}
  /* vibrant: rounded colour-tint tile */
  .fig-vibrant{border-radius:18px;padding:1.3rem;background:color-mix(in srgb,var(--fc) 14%,var(--panel));border:1px solid color-mix(in srgb,var(--fc) 30%,transparent)}
  .fig-vibrant .fic{background:var(--fc);color:#fff;margin-bottom:.7rem}
  .fig-vibrant b{color:var(--ink)}

  .banner-row{display:flex;gap:1rem;margin-bottom:1.1rem;flex-wrap:wrap}
  .banner{flex:1 1 60%;min-width:260px;border-radius:16px;padding:1.2rem 1.4rem;color:#fff;position:relative;overflow:hidden;
    background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 55%,#10121a))}
  .banner h2{font-size:1.25rem;font-weight:800;margin-bottom:.2rem}
  .banner p{opacity:.9;max-width:46ch;font-size:.92rem}
  .banner .bl{position:absolute;border-radius:50%;background:rgba(255,255,255,.12)}
  .banner .bl1{width:160px;height:160px;right:-40px;top:-60px}.banner .bl2{width:100px;height:100px;right:100px;bottom:-50px;background:rgba(255,255,255,.08)}
  .ann-card{flex:1 1 32%;min-width:220px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1rem 1.2rem;box-shadow:var(--shadow);margin-bottom:1.1rem}
  .banner-row .ann-card{margin-bottom:0}
  {{-- Same size as every other card heading (.card h3), not smaller: this card's title was reading weaker than its siblings on the same page. --}}
  .ann-card h3{font-size:1rem;font-weight:800;margin-bottom:.5rem}
  .ann-list{list-style:none;display:flex;flex-direction:column;gap:.5rem;max-height:120px;overflow-y:auto}
  .ann-list li{font-size:.88rem;padding-bottom:.5rem;border-bottom:1px solid var(--line)}
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
  $announcementsCard = view('school.widgets.announcements', ['announcementNotifications' => $announcementNotifications])->render();
@endphp

@if($layout === 'vibrant' || $layout === 'modern')
  @if($show('greeting') || $show('announcements'))
  <div class="banner-row">
    @if($show('greeting'))
    <div class="banner">
      <span class="bl bl1"></span><span class="bl bl2"></span>
      <div style="position:relative">
        <h2>Hello, {{ \Illuminate\Support\Str::before(auth()->user()->name,' ') ?: auth()->user()->name }}.</h2>
        <p>Welcome to {{ $school->name }}. Here is a snapshot of your school today.</p>
      </div>
    </div>
    @endif
    @if($show('announcements')){!! $announcementsCard !!}@endif
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
  @if($show('announcements')){!! $announcementsCard !!}@endif
@endif

{{--
  Quick actions (Dashboard Design Cross-Check, 2026-07-13: "Add Staff /
  Register Student / Publish Results / Assign Class, Partial or Blocked,
  real screens, just not linked as a one-click action from this
  dashboard"; broadened 2026-08-07 per direct feedback: "the quick
  actions buttons here are too small, let's add more and it should be
  related to what the user role have access to in their menus"). No
  longer restricted to the admin/school_admin/registrar role kinds —
  every viewer sees whichever of the catalog below they actually hold
  the permission for, the same "role relevant, not role hardcoded"
  principle every other permission gated link in the product already
  follows; the card itself only renders if at least one button
  qualifies, so a role with none of these (e.g. a plain student) never
  sees an empty shell.
--}}
@php
  $qaRbac = app(\App\Services\Rbac\Rbac::class);
  $qaUser = auth()->user();
  $qaCan = fn (string $perm) => $qaRbac->check($qaUser, $school, $perm);
  $quickActions = array_filter([
      $qaCan('staff.create') ? ['label' => 'Add staff', 'href' => route('school.staff.create', ['school' => $school->slug]), 'primary' => true] : null,
      $qaCan('students.create') ? ['label' => 'Register student', 'href' => route('school.students.create', ['school' => $school->slug])] : null,
      $qaCan('students.update') ? ['label' => 'Assign class', 'href' => route('school.students', ['school' => $school->slug])] : null,
      $qaCan('attendance.create') ? ['label' => 'Take attendance', 'href' => route('school.attendance.index', ['school' => $school->slug])] : null,
      $qaCan('examinations.create') ? ['label' => 'Enter results', 'href' => route('school.examinations.index', ['school' => $school->slug])] : null,
      ($qaCan('report_cards.assign_review') || $qaCan('report_cards.release')) ? ['label' => 'Publish results', 'href' => route('school.report-cards.pending-review', ['school' => $school->slug])] : null,
      $qaCan('finance.update') ? ['label' => 'Verify payments', 'href' => route('school.finance.payments.index', ['school' => $school->slug])] : null,
      $qaCan('admissions.decide') ? ['label' => 'Review applicants', 'href' => route('school.admissions.pipeline', ['school' => $school->slug])] : null,
      $qaCan('communication.broadcast') ? ['label' => 'Send announcement', 'href' => route('school.announcements.create', ['school' => $school->slug])] : null,
      $qaCan('calendar.create') ? ['label' => 'Create event', 'href' => route('school.calendar.create', ['school' => $school->slug])] : null,
  ]);
@endphp
@if(count($quickActions))
  <style>
    .qa-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:.7rem}
    .qa-grid a{padding:.7rem .9rem;font-size:.88rem;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    @media(max-width:900px){.qa-grid{grid-template-columns:repeat(3,1fr)}}
    @media(max-width:560px){.qa-grid{grid-template-columns:repeat(2,1fr)}}
  </style>
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.6rem">Quick actions</h3>
    <div class="qa-grid">
      @foreach($quickActions as $qa)
        <a class="btn {{ !empty($qa['primary']) ? 'btn-primary' : 'btn-ghost' }}" href="{{ $qa['href'] }}">{{ $qa['label'] }}</a>
      @endforeach
    </div>
  </div>
@endif

@if($show('stats') && count($figs))
<div class="figs" id="figsGrid">
  @foreach($figs as $i => $f)
    @php $figExtraClass = $i >= 4 ? ' fig-extra' : ''; @endphp
    {{-- Every card links to the real screen it summarises (user
         directive 2026-07-16: "MAKE EACH CARD CLICKABLE THAT IT TAKES
         ONE TO THE ACTUAL PAGE"), a full-cover transparent link laid
         over the card rather than changing the div into an <a> outright,
         so the existing per-layout markup below stays untouched; a fig
         with no href (not every role's dashboard sets one yet) simply
         renders without the overlay, unclickable exactly as before. --}}
    {{-- A fig with a 'popup' key instead of 'href' opens an in page
         dialog rather than navigating (user request 2026-07-27: "parent
         can click on the card to view... a pop up"), same full-cover
         transparent-link overlay technique, just a button instead of an
         anchor since it never leaves the page. --}}
    @php $figLinkAttrs = ! empty($f['href']) ? 'href="'.e($f['href']).'"' : (! empty($f['popup']) ? 'href="javascript:void(0)" data-open-popup="'.e($f['popup']).'"' : null); @endphp
    @switch($layout)
      @case('erp')
        <div class="fig fig-erp{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}">@if($figLinkAttrs)<a class="fig-link" {!! $figLinkAttrs !!} aria-label="{{ strip_tags($f['l']) }}"></a>@endif<span class="fic">@include('layouts.partials.icon',['name'=>$f['ic']])</span><span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></span></div>
        @break
      @case('modern')
        <div class="fig fig-modern{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}">@if($figLinkAttrs)<a class="fig-link" {!! $figLinkAttrs !!} aria-label="{{ strip_tags($f['l']) }}"></a>@endif<span class="ring"><i>@include('layouts.partials.icon',['name'=>$f['ic']])</i></span><span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></span></div>
        @break
      @case('vibrant')
        <div class="fig fig-vibrant{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}">@if($figLinkAttrs)<a class="fig-link" {!! $figLinkAttrs !!} aria-label="{{ strip_tags($f['l']) }}"></a>@endif<span class="fic">@include('layouts.partials.icon',['name'=>$f['ic']])</span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></div>
        @break
      @default
        <div class="fig fig-classic{{ $figExtraClass }}" style="--fc:{{ $f['c'] }}">@if($figLinkAttrs)<a class="fig-link" {!! $figLinkAttrs !!} aria-label="{{ strip_tags($f['l']) }}"></a>@endif<span class="fic">@include('layouts.partials.icon',['name'=>$f['ic']])</span><span><b>{{ $f['n'] }}</b><span>{{ $f['l'] }}</span></span></div>
    @endswitch
  @endforeach
</div>
@if(count($figs) > 4)
  <div style="text-align:center;margin:-.3rem 0 1.1rem">
    <button type="button" class="btn btn-ghost btn-sm" id="figsMoreToggle" data-more-label="View more" data-less-label="View less">View more</button>
  </div>
@endif
@endif

@if($role['panel'] && $show('role_panel'))
  @include('school.widgets.role-panel', ['panel' => $role['panel']])
@endif

@php
  $notes = view('school.widgets.notifications', ['feed' => $feed, 'school' => $school])->render();
  $cal = view('school.widgets.calendar', ['upcoming' => $upcoming])->render();
  $holidays = view('school.widgets.holidays', ['termKeyDates' => $termKeyDates])->render();
@endphp

<div class="grid cols-3">
  @if($show('notifications')){!! $notes !!}@endif
  @if($show('calendar')){!! $cal !!}@endif
  @if($show('holidays')){!! $holidays !!}@endif
</div>
@endsection

@push('scripts')
<script>
document.getElementById('figsMoreToggle')?.addEventListener('click', function(){
  var grid = document.getElementById('figsGrid');
  var open = grid.classList.toggle('show-more');
  this.textContent = open ? this.dataset.lessLabel : this.dataset.moreLabel;
});

// A stat tile's per-child breakdown popup (user request 2026-07-27:
// "parent can click on the card to view... a pop up"), same accessible
// dialog helper the payment countdown popup already established.
(function(){
  var closeCurrent = null;
  function close(scrim){
    scrim.classList.remove('show');
    if (closeCurrent) { closeCurrent(); closeCurrent = null; }
  }
  document.addEventListener('click', function(e){
    var opener = e.target.closest('[data-open-popup]');
    if (opener) {
      e.preventDefault();
      var scrim = document.getElementById(opener.dataset.openPopup);
      if (!scrim) return;
      scrim.classList.add('show');
      var closeBtn = scrim.querySelector('[data-close-popup]');
      closeCurrent = window.SkulosDialog?.open(scrim, {autofocus: closeBtn, onEscape: function(){ close(scrim); }});
      return;
    }
    var closer = e.target.closest('[data-close-popup]');
    if (closer) {
      close(document.getElementById(closer.dataset.closePopup));
      return;
    }
    if (e.target.classList.contains('stat-popup-scrim')) {
      close(e.target);
    }
  });
})();
</script>
@endpush
