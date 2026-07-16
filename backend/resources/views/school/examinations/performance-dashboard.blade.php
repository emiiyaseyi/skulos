@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Student Performance Dashboard'])
@section('title', 'Student Performance Dashboard')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Student Performance Dashboard</h1>
    <p class="subtitle">Result trends and performance across {{ $term?->name ?? 'the school' }}.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Enter results</a>
</div>

<div class="grid" style="grid-template-columns:repeat(3, 1fr);gap:1rem;margin-bottom:1.25rem">
  <div class="card stat" style="padding:1rem"><b>{{ $termAverage !== null ? "{$termAverage}%" : 'N/A' }}</b><span>Term average</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ number_format($studentsAssessed) }}</b><span>Students assessed</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ count($atRisk) }}</b><span>Performance at risk</span></div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.3rem">Result performance trend</h3>
  <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">The real school wide average across each of the last {{ count($trend) }} term(s) with recorded scores.</p>
  @if(empty($trend))
    <p class="muted">No results have been recorded yet.</p>
  @else
    @include('school.widgets._trend-line', [
      'values' => array_column($trend, 'average'),
      'labels' => array_column($trend, 'label'),
      'ariaLabel' => 'Result performance trend across recent terms',
    ])
  @endif
</div>

@if($classRates->isNotEmpty())
  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.3rem">By class</h3>
    <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">Average score per class for {{ $term?->name }}, highest first.</p>
    <div style="display:flex;flex-direction:column;gap:.6rem">
      @foreach($classRates as $row)
        <div>
          <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem">
            <strong>{{ $row['label'] }}</strong><span class="muted">{{ $row['average'] }}%</span>
          </div>
          <div style="background:var(--soft);border-radius:999px;height:.5rem;overflow:hidden">
            <div style="background:var(--accent);height:100%;width:{{ $row['average'] }}%"></div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
@endif

@if(empty($atRisk))
  <div class="card"><p class="muted">No student is currently below the school's {{ $threshold }}% at risk threshold{{ $term ? '' : ' (no current term set)' }}.</p></div>
@else
  @include('school.widgets._students-at-risk', [
    'items' => $atRisk,
    'title' => 'Performance at risk',
    'subtitle' => "Every student below the school's {$threshold}% threshold this term, lowest average first.",
  ])
@endif
@endsection
