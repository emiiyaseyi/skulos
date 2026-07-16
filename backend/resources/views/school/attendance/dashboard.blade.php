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

<div class="grid" style="grid-template-columns:repeat(4, 1fr);gap:1rem;margin-bottom:1.25rem">
  <div class="card stat" style="padding:1rem"><b>{{ $todayRate !== null ? "{$todayRate}%" : 'N/A' }}</b><span>Today's rate</span></div>
  @foreach($statuses as $status)
    <div class="card stat" style="padding:1rem"><b>{{ $todayCounts[$status] ?? 0 }}</b><span>{{ ucfirst($status) }} today</span></div>
  @endforeach
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.3rem">Attendance rate trend</h3>
  <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">Present rate for each of the last 14 real school days (weekends and holidays excluded).</p>
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
    <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">Present rate per class over the same 14 day window, highest first.</p>
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
