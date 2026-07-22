@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Timetable'])
@section('title', 'Timetable')

@push('head')
<style>
  .tt-grid{width:100%;border-collapse:collapse;font-size:.82rem}
  .tt-grid th,.tt-grid td{border:1px solid var(--line);padding:.5rem;vertical-align:top}
  .tt-grid th{background:var(--bg);font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mut-dim)}
  .tt-grid .tt-period-col{white-space:nowrap;font-weight:700}
  .tt-grid .tt-period-time{display:block;font-weight:400;color:var(--mut-dim);font-size:.72rem}
  .tt-grid select{width:100%;font-size:.78rem;margin-bottom:.3rem}
  .tt-break-row td{background:var(--bg);color:var(--mut-dim);font-style:italic;text-align:center}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Timetable</h1>
    <p class="subtitle">Pick a class to build or edit its weekly schedule.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.periods', ['school' => $school->slug]) }}">Manage periods</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.exam.index', ['school' => $school->slug]) }}">Examination timetable</a>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:14rem">
      <label for="class_arm_id">Class</label>
      <select id="class_arm_id" name="class_arm_id" onchange="this.form.submit()">
        <option value="">Select a class</option>
        @foreach($classArmsBySection as $sectionName => $arms)
          <optgroup label="{{ $sectionName }}">
            @foreach($arms as $arm)
              <option value="{{ $arm->id }}" @selected($selectedArm && $selectedArm->id === $arm->id)>{{ $arm->label() }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
    </div>
  </form>
</div>

@if($periods->isEmpty())
  <div class="card"><p class="muted">Set up the <a href="{{ route('school.timetable.periods', ['school' => $school->slug]) }}">period catalog</a> first.</p></div>
@elseif(! $selectedArm)
  <div class="card"><p class="muted">Select a class above to build its timetable.</p></div>
@else
  <form method="POST" action="{{ route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $selectedArm->id]) }}">
    @csrf
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
                <td colspan="{{ count($weekDays) }}">{{ $period->name }}, not a teaching slot</td>
              </tr>
            @else
              <tr>
                <td class="tt-period-col">{{ $period->name }}<span class="tt-period-time">{{ $period->timeLabel() }}</span></td>
                @foreach($weekDays as $dayKey => $dayLabel)
                  @php($entry = $grid[$dayKey.':'.$period->id] ?? null)
                  <td>
                    <select name="cells[{{ $dayKey }}][{{ $period->id }}][subject_id]">
                      <option value="">Free</option>
                      @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}" @selected($entry && $entry->subject_id === $subject->id)>{{ $subject->name }}</option>
                      @endforeach
                    </select>
                    <select name="cells[{{ $dayKey }}][{{ $period->id }}][staff_id]">
                      <option value="">No teacher set</option>
                      @foreach($staffOptions as $staff)
                        <option value="{{ $staff->id }}" @selected($entry && $entry->staff_id === $staff->id)>{{ $staff->fullName() }}</option>
                      @endforeach
                    </select>
                  </td>
                @endforeach
              </tr>
            @endif
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
    <p class="hint" style="margin-top:.6rem">Leave a slot on "Free" to remove it. A teacher already booked into another class at the exact same day and period is skipped, not double booked, and reported after saving.</p>
    <button class="btn btn-primary" type="submit" style="margin-top:.6rem">Save timetable</button>
  </form>
@endif
@endsection
