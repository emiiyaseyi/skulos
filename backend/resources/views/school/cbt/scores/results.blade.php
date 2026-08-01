@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'All Results'])
@section('title', 'All Results')

@section('content')
@php
  $statusBadge = fn (string $s) => match ($s) {
    'in_progress' => ['In progress', 'badge-warn'],
    'suspended' => ['Suspended', 'badge-warn'],
    'graded' => ['Graded', 'badge-ok'],
    default => ['Submitted', 'badge-ok'],
  };
  $statusTones = ['in_progress' => '#fd7e14', 'submitted' => '#0d6efd', 'auto_submitted' => '#0d6efd',
                  'graded' => '#198754', 'suspended' => '#dc3545'];
@endphp

@include('school.cbt._banner', [
  'icon' => '&#128200;',
  'title' => 'All Results',
  'subtitle' => 'Every attempt across every assessment in the school.',
  'links' => [
    ['label' => 'Score matrix', 'url' => route('school.cbt.scores.matrix', ['school' => $school->slug]), 'tone' => 'cyan'],
    ['label' => 'Admin Backend', 'url' => route('school.cbt.console.index', ['school' => $school->slug]), 'tone' => 'grey'],
    ['label' => 'Export CSV', 'url' => route('school.cbt.scores.results.export', ['school' => $school->slug]), 'tone' => 'green'],
  ],
])

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="cbt-stats">
  @foreach($breakdown as $key => $total)
    <div class="cbt-stat" style="background:{{ $statusTones[$key] ?? 'var(--accent)' }}">
      <b>{{ $total }}</b><span>{{ ucfirst(str_replace('_', ' ', $key)) }}</span>
    </div>
  @endforeach
</div>

<form method="GET" action="{{ route('school.cbt.scores.results', ['school' => $school->slug]) }}"
      class="card" style="margin-bottom:1.1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
  <div class="field" style="margin:0;flex:1;min-width:12rem">
    <label for="assessment">Assessment</label>
    <select id="assessment" name="assessment">
      <option value="">All assessments</option>
      @foreach($assessments as $a)
        <option value="{{ $a->id }}" @selected((string) $assessmentId === (string) $a->id)>{{ $a->title }}</option>
      @endforeach
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">Any</option>
      @foreach(['in_progress' => 'In progress', 'submitted' => 'Submitted', 'auto_submitted' => 'Auto submitted', 'graded' => 'Graded', 'suspended' => 'Suspended'] as $key => $label)
        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <button class="btn btn-primary btn-sm" type="submit">Apply</button>
</form>

<div class="card">
  @if($sessions->isEmpty())
    <p class="muted">No attempts match those filters.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Taker</th><th>Assessment</th><th>Status</th><th>Score</th><th>%</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
        @foreach($sessions as $session)
          @php([$statusLabel, $statusClass] = $statusBadge($session->status))
          @php($pct = $reporting->percentage((float) $session->total_score, (float) $session->max_score))
          <tr>
            <td data-label="Taker"><div class="cbt-person"><strong>{{ $session->takeable?->name ?? 'Unknown' }}</strong></div></td>
            <td class="muted" data-label="Assessment">{{ $session->assessment?->title }}</td>
            <td data-label="Status"><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
            <td data-label="Score">{{ $session->total_score !== null ? $session->total_score.' / '.$session->max_score : '—' }}</td>
            <td class="muted" data-label="%">{{ $pct !== null ? $pct.'%' : '—' }}</td>
            <td class="muted" data-label="Submitted">{{ $session->submitted_at?->format('d M H:i') ?? '—' }}</td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.scores.index', ['school' => $school->slug, 'assessmentId' => $session->assessment_id]) }}">Scores</a>
              <form method="POST" action="{{ route('school.cbt.scores.void', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline"
                    onsubmit="return confirm('Void this attempt? It will score zero.')">
                @csrf
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Void</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $sessions->links() }}</div>
  @endif
</div>
@endsection
