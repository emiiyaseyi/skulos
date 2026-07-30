@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Attendance Dashboard'])
@section('title', 'Attendance Dashboard')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Attendance Dashboard</h1>
    <p class="subtitle">Trends and comparisons across the last 14 school days.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.attendance.index', ['school' => $school->slug]) }}">Mark attendance</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    @if($canViewStaff)
      <div class="field" style="margin:0">
        <label for="att_type">Whose attendance</label>
        <select id="att_type" name="type" onchange="this.form.requestSubmit()">
          <option value="student" @selected($type === 'student')>Students</option>
          <option value="staff" @selected($type === 'staff')>Staff</option>
        </select>
      </div>
    @endif
    <div class="field" style="margin:0">
      <label for="att_date">Date</label>
      <input id="att_date" type="date" name="date" value="{{ $selectedDate }}" max="{{ now()->toDateString() }}" onchange="this.form.requestSubmit()" />
    </div>
    @if(! $isToday)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.attendance.dashboard', ['school' => $school->slug, 'type' => $type]) }}">Back to today</a>
    @endif
  </form>
</div>

{{--
  Kept as its own full-width card rather than folded into the grid
  below (found 2026-07-26: 5 cards, the rate plus 4 statuses, in a
  4-column grid dangles a lone 5th card at every breakpoint, on
  desktop and at the 2-column tablet step alike, the standing "grids
  must resolve to complete rows, pick a column count that divides
  evenly" rule). The 4 status cards on their own divide cleanly into
  the existing cols-4 grid at every breakpoint (4, then 2x2, then a
  single column), the same tested pattern used everywhere else.
--}}
<div class="card stat" style="padding:1rem;margin-bottom:1rem"><b>{{ $todayRate !== null ? "{$todayRate}%" : 'N/A' }}</b><span>{!! $isToday ? "Today's rate" : 'Rate on '.e(\Illuminate\Support\Carbon::parse($selectedDate)->format('j M')) !!}</span></div>
<div class="grid cols-4" style="gap:1rem;margin-bottom:1.25rem">
  @foreach($statuses as $status)
    <div class="card stat" style="padding:1rem"><b>{{ $todayCounts[$status] ?? 0 }}</b><span>{{ ucfirst($status) }} {{ $isToday ? 'today' : 'that day' }}</span></div>
  @endforeach
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.3rem">Attendance rate trend</h3>
  <p class="muted" style="margin-bottom:.8rem">Present rate for each of the last 14 real school days (weekends and holidays excluded).</p>
  @if($trend->filter(fn ($t) => $t['rate'] !== null)->isEmpty())
    <p class="muted">No attendance has been marked in this window yet.</p>
  @else
    @include('school.widgets._trend-line', [
      'values' => $trend->pluck('rate')->all(),
      'labels' => $trend->pluck('label')->all(),
      'ariaLabel' => 'Attendance rate trend across the last 14 school days',
    ])
  @endif
</div>

@if($classRates->isNotEmpty())
  <div class="card">
    <h3 style="margin-bottom:.3rem">By class</h3>
    <p class="muted" style="margin-bottom:.8rem">Present rate per class over the same 14 day window, highest first.</p>
    <div style="display:flex;flex-direction:column;gap:.6rem">
      @foreach($classRates as $row)
        <div>
          <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem">
            <strong>{{ $row['label'] }}</strong><span class="muted">{{ $row['rate'] }}%</span>
          </div>
          <div style="background:var(--soft);border-radius:999px;height:.5rem;overflow:hidden">
            <div style="background:var(--accent);height:100%;width:{{ $row['rate'] }}%"></div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
@endif
@endsection
