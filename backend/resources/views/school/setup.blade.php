@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Guided Setup'])
@section('title', 'Guided Setup')

@push('head')
<style>
  .setup-step{display:flex;gap:.9rem;align-items:flex-start}
  .setup-num{width:2rem;height:2rem;border-radius:50%;background:var(--soft);border:1px solid var(--line);display:grid;place-items:center;font-weight:800;flex-shrink:0;font-size:.9rem}
  .setup-num.done{background:var(--ok);color:#fff;border-color:var(--ok)}
  .setup-body{flex:1}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Guided Setup</h1>
    <p class="subtitle">One time setup for a new school. Each step is safe to run once, and you can always fine tune anything afterwards in Settings or Classes &amp; Subjects.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">{{ $allDone ? 'Back to dashboard' : 'Skip for now' }}</a>
</div>

@if($allDone)
  <div class="card" style="margin-bottom:1.1rem;border-color:var(--ok)">
    <p style="margin:0">Every step below is already done. You can still revisit any of them any time from Settings or Classes &amp; Subjects.</p>
  </div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <div class="setup-step">
    <div class="setup-num {{ $hasSession ? 'done' : '' }}">{{ $hasSession ? '&#10003;' : '1' }}</div>
    <div class="setup-body">
      <h3 style="margin-bottom:.3rem">Academic session &amp; term</h3>
      @if($hasSession)
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
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="setup-step">
    <div class="setup-num {{ $hasClasses ? 'done' : '' }}">{{ $hasClasses ? '&#10003;' : '2' }}</div>
    <div class="setup-body">
      <h3 style="margin-bottom:.3rem">Class structure</h3>
      @if($hasClasses)
        <p class="muted" style="margin-bottom:0">{{ $classCount }} class(es) set up. Add, rename, or load more tiers any time under Classes &amp; Subjects.</p>
      @else
        <p class="muted" style="margin-bottom:.7rem">Tick any tier to create its section, classes, and default subjects together, in one click.</p>
        @if($canManageAcademics)
          <form method="POST" action="{{ route('school.academics.class-structure.apply', ['school' => $school->slug]) }}">
            @csrf
            <div class="flagbox" style="margin-bottom:.8rem;flex-wrap:wrap">
              @foreach($classCatalogs as $key => $tier)
                <label><input type="checkbox" name="tiers[]" value="{{ $key }}" style="width:auto" /> {{ config('skulos.default_subjects.'.$key.'.label', ucfirst($key)) }}</label>
              @endforeach
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Load selected tiers</button>
          </form>
        @endif
      @endif
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="setup-step">
    <div class="setup-num {{ $hasHouses ? 'done' : '' }}">{{ $hasHouses ? '&#10003;' : '3' }}</div>
    <div class="setup-body">
      <h3 style="margin-bottom:.3rem">Houses</h3>
      @if($hasHouses)
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
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="setup-step">
    <div class="setup-num {{ $hasAssessment ? 'done' : '' }}">{{ $hasAssessment ? '&#10003;' : '4' }}</div>
    <div class="setup-body">
      <h3 style="margin-bottom:.3rem">Assessment structure</h3>
      @if($hasAssessment)
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
    </div>
  </div>
</div>

<a class="btn btn-primary" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">Go to dashboard</a>
@endsection
