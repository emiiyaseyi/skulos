@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Guided Setup'])
@section('title', 'Guided Setup')

@push('head')
<style>
  .setup-rail{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.1rem}
  .setup-dot{display:inline-flex;align-items:center;gap:.45rem;padding:.4rem .8rem .4rem .4rem;border-radius:999px;background:var(--soft);border:1px solid var(--line);font-size:.8rem;font-weight:600;color:var(--mut);text-decoration:none;line-height:1.1;max-width:100%;overflow:hidden}
  .setup-dot.done{color:var(--ok)}
  .setup-dot.active{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
  .setup-num{width:1.5rem;height:1.5rem;border-radius:50%;background:var(--line);display:flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0;font-size:.72rem;line-height:1}
  .setup-dot.done .setup-num{background:var(--ok);color:#fff}
  .setup-dot.active .setup-num{background:var(--accent-ink);color:var(--accent)}
  /* min-width:0 is the load-bearing part (2026-08-15): a flex item
     defaults to min-width:auto, meaning it refuses to shrink below its
     own content width, so a nowrap step title ignored the pill's
     max-width and spilled out over the number circle instead of
     truncating. Reported as the icon and text overlapping. */
  .setup-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
  .setup-nav{display:flex;justify-content:space-between;margin-top:1.1rem;gap:.6rem;flex-wrap:wrap}
  /* Mobile: the rail's pills can outgrow a narrow phone width with 9
     steps (design system rule: "genuinely usable on a phone, not just
     responsive-looking"), let the rail scroll sideways at that width
     instead of the flex-wrap causing pills to squeeze/wrap awkwardly. */
  @media (max-width:520px){
    .setup-rail{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:.3rem}
    .setup-dot{flex-shrink:0}
  }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Guided Setup</h1>
    <p class="subtitle">Step {{ $current + 1 }} of {{ count($steps) }}. Safe to run once, and you can always fine tune anything afterwards in Settings or Classes &amp; Subjects.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">{{ $allDone ? 'Back to dashboard' : 'Skip for now' }}</a>
</div>

<div class="setup-rail">
  @foreach($steps as $i => $step)
    <a class="setup-dot @if($step['done']) done @endif @if($i === $current) active @endif" href="{{ route('school.setup', ['school' => $school->slug, 'step' => $i + 1]) }}">
      <span class="setup-num">{{ $step['done'] ? '✓' : $i + 1 }}</span><span class="setup-label">{{ $step['title'] }}</span>
    </a>
  @endforeach
</div>

<div class="card" style="margin-bottom:1.1rem">
  @php($step = $steps[$current])

  @if($step['key'] === 'welcome')
    <h3 style="margin-bottom:.4rem">Welcome to {{ $school->name }}</h3>
    <p class="muted" style="margin-bottom:0">This walks you through the one time setup pieces every new school needs: branding, your first academic session, classes, houses, assessment structure, then adding your students and staff. Each step is optional to do right now, you can always come back.</p>

  @elseif($step['key'] === 'branding')
    <h3 style="margin-bottom:.3rem">Branding</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">Your school's colour and welcome message are set. Fine tune the logo, colour, or login page look any time under Settings &gt; Branding.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Give your school its own colour and welcome message. The full branding screen also has a logo upload and login page layout picker.</p>
      @if($canManageBranding)
        <form method="POST" action="{{ route('school.branding.update', ['school' => $school->slug]) }}" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
          @csrf
          <div class="field" style="margin-bottom:0">
            <label for="setup_brand_color">Brand colour</label>
            <input id="setup_brand_color" name="brand_color" type="color" value="{{ $school->brand_color ?: '#123524' }}" style="width:3.4rem;padding:.2rem" />
          </div>
          <div class="field" style="margin-bottom:0;flex:1;min-width:14rem">
            <label for="setup_welcome_headline">Welcome message</label>
            <input id="setup_welcome_headline" name="welcome_headline" placeholder="e.g. Welcome to {{ $school->name }}" />
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Save</button>
        </form>
        <p class="muted" style="margin-top:.6rem;margin-bottom:0"><a href="{{ route('school.branding.edit', ['school' => $school->slug]) }}">Open the full branding screen</a> for a logo upload and more layout choices.</p>
      @endif
    @endif

  @elseif($step['key'] === 'session')
    <h3 style="margin-bottom:.3rem">Academic session &amp; term</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">Current session: <strong>{{ $currentSession?->name }}</strong>. Manage sessions and terms any time under Settings.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Create your first academic session, e.g. enter 2025 for the 2025/2026 session, and its three terms are added automatically.</p>
      @if($canManageAcademics)
        <form method="POST" action="{{ route('school.academics.sessions.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
          @csrf
          <div class="field" style="margin-bottom:0">
            <label for="setup_start_year">Start year</label>
            <input id="setup_start_year" name="start_year" type="number" min="2000" max="2100" placeholder="e.g. 2025" required />
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Create session &amp; terms</button>
        </form>
      @endif
    @endif

  @elseif($step['key'] === 'classes')
    <h3 style="margin-bottom:.3rem">Class structure</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">{{ $classCount }} class(es) set up. Add, rename, or load more tiers any time under Classes &amp; Subjects.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Tick any tier to create its section, classes, and default subjects together, in one click.</p>
      @if($canManageAcademics)
        <form method="POST" action="{{ route('school.academics.class-structure.apply', ['school' => $school->slug]) }}">
          @csrf
          <div class="flagbox" style="margin-bottom:.8rem;flex-wrap:wrap">
            @foreach($classCatalogs as $key => $tier)
              <label><input type="checkbox" name="tiers[]" value="{{ $key }}" /> {{ config('skulos.default_subjects.'.$key.'.label', ucfirst($key)) }}</label>
            @endforeach
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Load selected tiers</button>
        </form>
      @endif
    @endif

  @elseif($step['key'] === 'houses')
    <h3 style="margin-bottom:.3rem">Houses</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">{{ $houseCount }} house(s) set up. Assign students or add more under Houses.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Most schools run 4 houses. Load the defaults, or add your own under Houses afterwards.</p>
      @if($canManageStudentSettings)
        <form method="POST" action="{{ route('school.houses.apply-defaults', ['school' => $school->slug]) }}">
          @csrf
          <button class="btn btn-primary btn-sm" type="submit">Load default houses</button>
        </form>
      @endif
    @endif

  @elseif($step['key'] === 'assessment')
    <h3 style="margin-bottom:.3rem">Assessment structure</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">Set up. Fine tune components under Results &gt; Assessment Structure.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Two Continuous Assessments (20 marks each) plus one Exam (60 marks), the common Nigerian school split. Adjust anything afterwards.</p>
      @if($canApproveResults)
        <form method="POST" action="{{ route('school.examinations.structure.apply-defaults', ['school' => $school->slug]) }}">
          @csrf
          <button class="btn btn-primary btn-sm" type="submit">Load default structure</button>
        </form>
      @endif
    @endif

  @elseif($step['key'] === 'students')
    <h3 style="margin-bottom:.3rem">Students</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">{{ $studentCount }} student(s) on record. Add or import more any time under Students.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Add your students one at a time, or upload a class list all at once.</p>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <a class="btn btn-primary btn-sm" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Add a student</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students.import', ['school' => $school->slug]) }}">Bulk import from CSV</a>
      </div>
    @endif

  @elseif($step['key'] === 'staff')
    <h3 style="margin-bottom:.3rem">Staff</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">{{ $staffCount }} staff member(s) on record. Add more any time under Staff.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Add your teachers and other staff, then give each of them a login from their profile.</p>
      <a class="btn btn-primary btn-sm" href="{{ route('school.staff.create', ['school' => $school->slug]) }}">Add a staff member</a>
    @endif

  @elseif($step['key'] === 'done')
    <h3 style="margin-bottom:.4rem">{{ $allDone ? "You're all set" : 'Continue whenever you are ready' }}</h3>
    <p class="muted" style="margin-bottom:.8rem">
      @if($allDone)
        Every step is done. {{ $school->name }} is ready to go.
      @else
        Come back to this page any time from the dashboard, it always picks up where you left off.
      @endif
    </p>
    <a class="btn btn-primary btn-sm" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">Go to dashboard</a>
  @endif
</div>

<div class="setup-nav">
  <div>
    @if($current > 0)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.setup', ['school' => $school->slug, 'step' => $current]) }}">Back</a>
    @endif
  </div>
  <div>
    @if($current < count($steps) - 1)
      <a class="btn btn-primary btn-sm" href="{{ route('school.setup', ['school' => $school->slug, 'step' => $current + 2]) }}">Next</a>
    @endif
  </div>
</div>
@endsection
