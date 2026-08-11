@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Promotion Report'])
@section('title', 'Promotion Report')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Promotion Report</h1>
    <p class="subtitle">
      {{ $run->term?->session?->name }} {{ $run->term?->name }} evaluated at a {{ $run->threshold }}% threshold, run {{ $run->created_at->diffForHumans() }}.
      @if($run->toTerm)Labeled as promoting into {{ $run->toTerm->session?->name }} {{ $run->toTerm->name }}.@endif
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.promotions.index', ['school' => $school->slug]) }}">All runs</a>
</div>

<div class="pcr-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin-bottom:1.1rem">
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $run->promoted_count }}</div>
    <div class="muted" style="font-size:.8rem">Promoted</div>
  </div>
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $run->repeated_count }}</div>
    <div class="muted" style="font-size:.8rem">Repeated</div>
  </div>
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $run->graduated_count }}</div>
    <div class="muted" style="font-size:.8rem">Graduated</div>
  </div>
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $run->skipped_count }}</div>
    <div class="muted" style="font-size:.8rem">Skipped (no results, etc.)</div>
  </div>
</div>
@php($total = $run->items->count())

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-top:0">Per class</h3>
  <div style="overflow-x:auto">
  <table class="table-stack">
    <thead><tr><th>Class</th><th>Total</th><th>Promoted</th><th>Repeated</th><th>Graduated</th><th>Not moved</th><th>% moved</th></tr></thead>
    <tbody>
      @foreach($byClassArm as $row)
        <tr>
          <td data-label="Class"><strong>{{ $row['label'] }}</strong></td>
          <td data-label="Total">{{ $row['total'] }}</td>
          <td data-label="Promoted" class="muted">{{ $row['promoted'] }}</td>
          <td data-label="Repeated" class="muted">{{ $row['repeated'] }}</td>
          <td data-label="Graduated" class="muted">{{ $row['graduated'] }}</td>
          <td data-label="Not moved" class="muted">{{ $row['not_moved'] }}</td>
          <td data-label="% moved">{{ $row['total'] ? round((($row['promoted'] + $row['graduated']) / $row['total']) * 100, 1) : 0 }}%</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0">Not moved ({{ $notMoved->count() }} of {{ $total }})</h3>
  @if($notMoved->isEmpty())
    <p class="muted" style="margin-bottom:0">Every evaluated student was promoted or graduated.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Student</th><th>From class</th><th>Average</th><th>Outcome</th><th>Reason</th></tr></thead>
      <tbody>
        @foreach($notMoved as $item)
          <tr>
            <td data-label="Student">{{ $item->student?->fullName() ?? 'Unknown' }}</td>
            <td data-label="From class">{{ $item->fromClassArm?->label() ?? 'Unknown' }}</td>
            <td data-label="Average" class="muted">{{ $item->average !== null ? $item->average.'%' : 'None' }}</td>
            <td data-label="Outcome">{{ \App\Models\Tenant\PromotionRunItem::outcomeLabel($item->outcome) }}</td>
            <td data-label="Reason" class="muted">{{ $item->reason }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
