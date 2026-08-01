@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Scores'])
@section('title', 'Scores: '.$assessment->title)

@section('content')
@php
  $sortUrl = fn (string $col) => route('school.cbt.scores.index', array_filter([
    'school' => $school->slug, 'assessmentId' => $assessment->id,
    'sort' => $col, 'dir' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc',
    'status' => $status ?: null, 'risk' => $risk ?: null, 'search' => $search ?: null,
  ]));
  $arrow = fn (string $col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
  $statusBadge = fn (string $s) => match ($s) {
    'in_progress' => ['In progress', 'badge-warn'],
    'suspended' => ['Suspended', 'badge-warn'],
    'graded' => ['Graded', 'badge-ok'],
    default => ['Submitted', 'badge-ok'],
  };
@endphp

@include('school.cbt._banner', [
  'icon' => '&#128202;',
  'title' => 'Scores',
  'subtitle' => $assessment->title,
  'links' => [
    ['label' => 'Manage assessment', 'url' => route('school.cbt.hub.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]), 'tone' => 'primary'],
    ['label' => 'Grading', 'url' => route('school.cbt.grader.queue', ['school' => $school->slug, 'assessmentId' => $assessment->id]), 'tone' => 'teal'],
    ['label' => 'All results', 'url' => route('school.cbt.scores.results', ['school' => $school->slug]), 'tone' => 'green'],
    ['label' => 'Score matrix', 'url' => route('school.cbt.scores.matrix', ['school' => $school->slug]), 'tone' => 'cyan'],
  ],
])

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<form method="GET" action="{{ route('school.cbt.scores.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}"
      class="card" style="margin-bottom:1.1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
  <div class="field" style="margin:0;flex:1;min-width:10rem">
    <label for="search">Search taker</label>
    <input id="search" name="search" type="search" value="{{ $search }}" />
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
  <div class="field" style="margin:0">
    <label for="risk">Risk</label>
    <select id="risk" name="risk">
      <option value="">Any</option>
      @foreach(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $key => $label)
        <option value="{{ $key }}" @selected($risk === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <button class="btn btn-primary btn-sm" type="submit">Apply</button>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.scores.export', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'format' => 'csv']) }}">Export CSV</a>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.scores.export', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'format' => 'tsv']) }}">Export for Excel</a>
</form>

{{-- Legend for the per-question pills, matching the reference. --}}
<div class="card" style="margin-bottom:1.1rem">
  <div class="cbt-legend">
    @foreach($stateLabels as $state => $label)
      <span><span class="cbt-qpill cbt-qpill-{{ $state }}" style="min-width:2rem;padding:.15rem .4rem">&nbsp;</span> {{ $label }}</span>
    @endforeach
  </div>
</div>

<div class="card">
  <p class="muted" style="margin-bottom:.8rem">{{ $sessions->count() }} attempt(s).</p>

  @if($sessions->isEmpty())
    <p class="muted">Nothing matches those filters.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead>
        <tr>
          <th><a href="{{ $sortUrl('name') }}" style="text-decoration:none;color:inherit">Taker{!! $arrow('name') !!}</a></th>
          <th>Status</th>
          <th><a href="{{ $sortUrl('score') }}" style="text-decoration:none;color:inherit">Score{!! $arrow('score') !!}</a></th>
          <th><a href="{{ $sortUrl('risk') }}" style="text-decoration:none;color:inherit">Risk{!! $arrow('risk') !!}</a></th>
          <th><a href="{{ $sortUrl('submitted') }}" style="text-decoration:none;color:inherit">Submitted{!! $arrow('submitted') !!}</a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($sessions as $session)
          @php([$statusLabel, $statusClass] = $statusBadge($session->status))
          <tr>
            <td data-label="Taker">
              <div class="cbt-person"><strong>{{ $session->takeable?->name ?? 'Unknown' }}</strong></div>
              {{-- Per-question pills, with the marking grader's name
                   under any answer a human marked. --}}
              <div style="display:flex;flex-wrap:wrap;gap:.25rem;margin-top:.4rem">
                @foreach($pills[$session->id] ?? [] as $pill)
                  <span class="cbt-qpill cbt-qpill-{{ $pill['state'] }}"
                        title="{{ $pill['label'] }}: {{ $pill['score'] }} / {{ $pill['max'] }}">
                    {{ $pill['label'] }}{{ $pill['state'] === 'rg' ? ' RG' : ($pill['state'] === 'na' ? ' NA' : ' ✓') }}
                    @if($pill['grader'])<small>{{ $pill['grader'] }}</small>@endif
                  </span>
                @endforeach
              </div>
            </td>
            <td data-label="Status"><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
            <td data-label="Score">
              {{ $session->total_score !== null ? $session->total_score.' / '.$session->max_score : '—' }}
            </td>
            <td data-label="Risk">{{ $session->risk_score }}</td>
            <td class="muted" data-label="Submitted">{{ $session->submitted_at?->format('d M H:i') ?? '—' }}</td>
            <td style="white-space:nowrap">
              <form method="POST" action="{{ route('school.cbt.scores.grant-retry', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Grant retry</button>
              </form>
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
  @endif
</div>
@endsection
