@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Timetable'])
@section('title', 'Timetable')

@push('head')
<style>
  .tt-grid{width:100%;border-collapse:collapse;font-size:.82rem}
  .tt-grid th,.tt-grid td{border:1px solid var(--line);padding:.5rem;vertical-align:top}
  .tt-grid th{background:var(--bg);font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mut-dim)}
  .tt-grid .tt-period-col{white-space:nowrap;font-weight:700}
  .tt-grid .tt-period-time{display:block;font-weight:400;color:var(--mut-dim);font-size:.72rem}
  .tt-grid .tt-cell b{display:block}
  .tt-grid .tt-cell span{color:var(--mut-dim);font-size:.76rem}
  .tt-break-row td{background:var(--bg);color:var(--mut-dim);font-style:italic;text-align:center}
  .tt-free{color:var(--mut-dim)}
  @media print{
    .appbar,.side,.impersonate-bar,.page-head .btn,.page-head form{display:none !important}
    .content{padding:0 !important}
    .card{box-shadow:none;border:none;break-inside:avoid;margin-bottom:.6rem !important}
  }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Timetable</h1>
    <p class="subtitle">{{ $subtitle }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem;align-items:center">
    @if($children->count() > 1)
      <form method="GET" style="display:flex;gap:.5rem;align-items:center">
        <select name="student_id" onchange="this.form.submit()">
          <option value="all" @selected(request()->query('student_id') === 'all')>All children</option>
          @foreach($children as $child)
            <option value="{{ $child->id }}" @selected($selectedChildId === $child->id && request()->query('student_id') !== 'all')>{{ $child->fullName() }}</option>
          @endforeach
        </select>
        <input type="hidden" name="term_id" value="{{ $term->id ?? 'all' }}" />
      </form>
    @endif
    @if($terms->isNotEmpty())
      {{-- User request 2026-07-28: "Timetable pages should also have
           term switcher too... the timetable term switcher should have
           the school current term as the default one." Defaults to the
           school's current term on a first visit (no term_id at all);
           "All terms" is its own explicit choice (term_id=all, the
           same sentinel ?student_id=all already uses above), showing
           the universal grid every school already had before this
           feature, unchanged; picking a real term layers that term's
           own saved overrides on top, if the admin has ever saved any
           for it. --}}
      <form method="GET" style="display:flex;gap:.5rem;align-items:center">
        @if(request()->query('student_id'))<input type="hidden" name="student_id" value="{{ request()->query('student_id') }}" />@endif
        <select name="term_id" onchange="this.form.submit()" style="min-width:13rem">
          <option value="all" @selected(! $term)>All terms</option>
          @foreach($terms as $t)
            <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
          @endforeach
        </select>
      </form>
    @endif
    @if(! empty($grid))
      <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.mine.export', array_filter(['school' => $school->slug, 'view' => request()->query('view'), 'student_id' => $selectedChildId, 'term_id' => $term->id ?? 'all'])) }}">Export CSV</a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">Print / Save as PDF</button>
    @endif
    @if($children->isNotEmpty())
      <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.exam.mine', array_filter(['school' => $school->slug, 'student_id' => $selectedChildId])) }}">Exam Timetable</a>
    @endif
  </div>
</div>

{{--
  User request 2026-07-22: "when the teacher clicks the timetable menu,
  he should be able to select class timetable or the My timetable
  option. Note that subject teachers wont see the class timetable
  option." Only rendered when this staff member is actually a class
  teacher for a class ($ownClassArm set); a subject teacher (or any
  other staff role) has no class of their own to toggle to, so this
  entire block is absent for them and the page just always shows their
  personal schedule, exactly as it did before this change.
--}}
@isset($ownClassArm)
  <div class="card" style="margin-bottom:1.1rem;padding:.6rem">
    <div style="display:flex;gap:.5rem">
      <a class="btn btn-sm {{ $view === 'class' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.timetable.mine', array_filter(['school' => $school->slug, 'view' => 'class', 'term_id' => $term->id ?? 'all'])) }}">Class Timetable</a>
      <a class="btn btn-sm {{ $view === 'personal' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.timetable.mine', array_filter(['school' => $school->slug, 'view' => 'personal', 'term_id' => $term->id ?? 'all'])) }}">My Timetable</a>
    </div>
  </div>
@endisset

@if($periods->isEmpty())
  <div class="card"><p class="muted">No timetable has been set up yet.</p></div>
@elseif($allGrids->isNotEmpty())
  {{-- "See all children's timetable at once" (user request 2026-07-27),
       one card per child, each with its own real grid, rather than
       switching one at a time. --}}
  @foreach($allGrids as $row)
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.6rem">{{ $row['student']->fullName() }}</h3>
      @php($grid = $row['grid'])
      @include('school.timetable._grid-table')
    </div>
  @endforeach
@elseif(empty($grid) && $children->isEmpty())
  <div class="card"><p class="muted">Nothing is scheduled for you yet.</p></div>
@else
  <div class="card">
    @include('school.timetable._grid-table')
  </div>
@endif
@endsection
