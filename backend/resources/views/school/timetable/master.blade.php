@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Master Timetable'])
@section('title', 'Master Timetable')

@push('head')
<style>
  .mtt-grid{width:100%;border-collapse:collapse;font-size:.78rem}
  .mtt-grid th,.mtt-grid td{border:1px solid var(--line);padding:.4rem .5rem;text-align:left;white-space:nowrap}
  .mtt-grid th{background:var(--soft);font-size:.68rem;text-transform:uppercase;letter-spacing:.03em}
  .mtt-grid td.mtt-class{font-weight:700;background:var(--panel)}
  .mtt-free{color:var(--mut-dim)}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Master Timetable</h1>
    <p class="subtitle">Every class's weekly schedule, in one place.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.index', ['school' => $school->slug]) }}">Back to Timetable</a>
</div>

@if($classGrids->isEmpty())
  <div class="card"><p class="muted">No classes set up yet.</p></div>
@else
  @php($teachingPeriods = $periods->where('is_teaching_period', true))
  @if($teachingPeriods->isEmpty())
    <div class="card"><p class="muted">Set up the <a href="{{ route('school.timetable.periods', ['school' => $school->slug]) }}">period catalog</a> first.</p></div>
  @else
    @foreach($weekDays as $dayKey => $dayLabel)
      <div class="card" style="margin-bottom:1.1rem">
        <h3 style="margin-bottom:.7rem">{{ $dayLabel }}</h3>
        <div style="overflow-x:auto">
        <table class="mtt-grid">
          <thead>
            <tr>
              <th>Class</th>
              @foreach($teachingPeriods as $period)
                <th>{{ $period->name }}<br><span class="muted" style="font-weight:400">{{ $period->timeLabel() }}</span></th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach($classGrids as $row)
              <tr>
                <td class="mtt-class">{{ $row['arm']->label() }}</td>
                @foreach($teachingPeriods as $period)
                  @php($entry = $row['grid'][$dayKey.':'.$period->id] ?? null)
                  <td>
                    @if($entry && $entry->subject)
                      {{ $entry->subject->name }}
                      @if($entry->staff)<br><span class="muted">{{ $entry->staff->fullName() }}</span>@endif
                    @else
                      <span class="mtt-free">Free</span>
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      </div>
    @endforeach
  @endif
@endif
@endsection
