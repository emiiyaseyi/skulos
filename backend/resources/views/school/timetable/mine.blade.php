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
          @foreach($children as $child)
            <option value="{{ $child->id }}" @selected($selectedChildId === $child->id)>{{ $child->fullName() }}</option>
          @endforeach
        </select>
      </form>
    @endif
    @if(! empty($grid))
      <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.mine.export', array_filter(['school' => $school->slug, 'view' => request()->query('view'), 'student_id' => $selectedChildId])) }}">Export CSV</a>
      <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">Print / Save as PDF</button>
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
      <a class="btn btn-sm {{ $view === 'class' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.timetable.mine', ['school' => $school->slug, 'view' => 'class']) }}">Class Timetable</a>
      <a class="btn btn-sm {{ $view === 'personal' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.timetable.mine', ['school' => $school->slug, 'view' => 'personal']) }}">My Timetable</a>
    </div>
  </div>
@endisset

@if($periods->isEmpty())
  <div class="card"><p class="muted">No timetable has been set up yet.</p></div>
@elseif(empty($grid) && $children->isEmpty())
  <div class="card"><p class="muted">Nothing is scheduled for you yet.</p></div>
@else
  <div class="card">
    <div style="overflow-x:auto">
    <table class="tt-grid">
      <thead>
        <tr>
          <th>Period</th>
          @foreach($weekDays as $dayKey => $dayLabel)<th>{{ $dayLabel }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($periods as $period)
          @if(! $period->is_teaching_period)
            <tr class="tt-break-row">
              <td class="tt-period-col">{{ $period->name }}<span class="tt-period-time">{{ $period->timeLabel() }}</span></td>
              <td colspan="{{ count($weekDays) }}">{{ $period->name }}</td>
            </tr>
          @else
            <tr>
              <td class="tt-period-col">{{ $period->name }}<span class="tt-period-time">{{ $period->timeLabel() }}</span></td>
              @foreach($weekDays as $dayKey => $dayLabel)
                @php($entry = $grid[$dayKey.':'.$period->id] ?? null)
                <td class="tt-cell">
                  @if($entry && $entry->subject)
                    <b>{{ $entry->subject->name }}</b>
                    <span>
                      {{ $entry->staff?->fullName() ?? 'No teacher set' }}
                      @if(isset($entry->classArm))&middot; {{ $entry->classArm->label() }}@endif
                    </span>
                  @else
                    <span class="tt-free">Free</span>
                  @endif
                </td>
              @endforeach
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif
@endsection
