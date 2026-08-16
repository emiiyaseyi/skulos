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
    @if($step['skipped'])
      <p class="muted" style="margin-bottom:.7rem">You skipped this step. Nothing about houses is set up.</p>
      @include('school.partials.setup-unskip', ['step' => 'houses'])
    @elseif($step['done'])
      <p class="muted" style="margin-bottom:0">{{ $houseCount }} house(s) set up. Assign students or add more under Houses.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Tick the ones your school uses, or type your own. You can change any of this later under Houses.</p>
      @if($canManageStudentSettings)
        @include('school.partials.setup-group-picker', [
          'kind' => 'houses',
          'options' => $defaultHouses,
          'label' => 'house',
        ])
      @endif
    @endif

  @elseif($step['key'] === 'clubs')
    <h3 style="margin-bottom:.3rem">Clubs</h3>
    @if($step['skipped'])
      <p class="muted" style="margin-bottom:.7rem">You skipped this step. Clubs stay switched off.</p>
      @include('school.partials.setup-unskip', ['step' => 'clubs'])
    @elseif($step['done'])
      <p class="muted" style="margin-bottom:0">Clubs are set up. Assign students, staff and parents under Clubs.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">
        Optional. Tick the clubs your school runs, or type your own. If you do not run clubs, skip
        this step and nothing about clubs appears anywhere.
      </p>
      @if($canManageStudentSettings)
        @include('school.partials.setup-group-picker', [
          'kind' => 'clubs',
          'options' => $defaultClubs,
          'label' => 'club',
        ])
      @endif
    @endif

  @elseif($step['key'] === 'assessment')
    <h3 style="margin-bottom:.3rem">Assessment structure</h3>
    @if($step['skipped'])
      <p class="muted" style="margin-bottom:.7rem">You skipped this step. Set it up later under Results &gt; Assessment Structure.</p>
      @include('school.partials.setup-unskip', ['step' => 'assessment'])
    @elseif($step['done'])
      <p class="muted" style="margin-bottom:0">Set up. Fine tune components under Results &gt; Assessment Structure.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">
        The common split is two Continuous Assessments of 20 marks plus an Exam of 60. Change any
        name or mark below, or clear a row you do not need. They must total 100 or less.
      </p>
      @if($canApproveResults)
        <form method="POST" action="{{ route('school.setup.assessment', ['school' => $school->slug]) }}">
          @csrf
          <table class="table-stack" style="margin-bottom:.8rem">
            <thead><tr><th>Component</th><th>Type</th><th>Max score</th></tr></thead>
            <tbody>
              @foreach(array_pad($defaultComponents, 5, ['name' => '', 'type' => 'ca', 'max_score' => '']) as $i => $component)
                <tr>
                  <td data-label="Component">
                    <input name="components[{{ $i }}][name]" value="{{ $component['name'] }}" maxlength="60" placeholder="e.g. First CA" />
                  </td>
                  <td data-label="Type">
                    {{-- A suggestion list, not a fixed dropdown
                         (2026-08-16): a school running tests, projects
                         or practicals rather than "continuous
                         assessment" and "exam" can type its own. --}}
                    <input name="components[{{ $i }}][type]" list="componentTypes" maxlength="40"
                           value="{{ ($component['type'] ?? '') === 'exam' ? 'Exam' : (($component['name'] ?? '') ? 'Continuous assessment' : '') }}"
                           placeholder="Type or pick" />
                  </td>
                  <td data-label="Max score">
                    <input name="components[{{ $i }}][max_score]" type="number" min="1" max="100" step="1"
                           value="{{ $component['max_score'] }}" style="max-width:7rem" />
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
          <datalist id="componentTypes">
            <option value="Continuous assessment"></option>
            <option value="Exam"></option>
            <option value="Test"></option>
            <option value="Project"></option>
            <option value="Practical"></option>
          </datalist>
          <button class="btn btn-primary btn-sm" type="submit">Save structure</button>
        </form>
      @endif
      @include('school.partials.setup-skip', ['step' => 'assessment'])
    @endif

  @elseif($step['key'] === 'students')
    <h3 style="margin-bottom:.3rem">Students</h3>
    @if($step['done'])
      <p class="muted" style="margin-bottom:0">{{ $studentCount }} student(s) on record. Add or import more any time under Students.</p>
    @else
      <p class="muted" style="margin-bottom:.7rem">Add your students one at a time, or upload a class list all at once.</p>

      {{-- Choose the columns before downloading (2026-08-16): a school
           at setup usually has names and classes to hand but not
           addresses or dates of birth, and a sheet full of columns they
           cannot complete reads as work they have failed to do. The
           columns the import genuinely cannot run without are ticked
           and locked, since a sheet missing those fails on upload
           rather than at the point of choosing. --}}
      <details style="margin-bottom:.9rem">
        <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Download a blank sheet to fill in</summary>
        <form method="GET" action="{{ route('school.setup.student-template', ['school' => $school->slug]) }}" style="margin-top:.8rem">
          <p class="muted" style="margin-bottom:.5rem">Tick what you are ready to fill in now. You can always add the rest later.</p>
          <div class="grid cols-3" style="gap:.35rem;margin-bottom:.8rem">
            @foreach($studentColumns as $key => $label)
              @php($locked = in_array($key, $mandatoryStudentColumns, true))
              <label class="module-check" @if($locked) title="Required, the import cannot run without it" @endif>
                <input type="checkbox" name="columns[]" value="{{ $key }}"
                       @checked(true) @disabled($locked) />
                {{ $label }}@if($locked) <span class="muted">(required)</span>@endif
              </label>
            @endforeach
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Download sheet</button>
        </form>
      </details>
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
