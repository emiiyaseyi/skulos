@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $assessment->title])
@section('title', 'Grading: '.$assessment->title)

@section('content')
@php
  $label = fn ($session) => $grader->anonymous ? 'Candidate #'.$session->id : ($session->takeable?->name ?? 'Unknown');
@endphp

<div class="page-head">
  <div>
    <h1 class="title">{{ $assessment->title }}</h1>
    <p class="subtitle">
      {{ $grader->isReviewer() ? 'Second review' : 'First grader' }}
      @if($grader->anonymous) &middot; marking blind @endif
      @if($grader->deadline) &middot; due {{ $grader->deadline->format('d M Y') }} @endif
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.grader.index', ['school' => $school->slug]) }}">All grading</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

@if($grader->anonymous)
  <div class="card" style="margin-bottom:1.1rem;border-left:4px solid var(--accent)">
    <p class="muted" style="margin:0">You are marking blind. Takers are shown only as candidate numbers, and their names are not sent to your browser at all.</p>
  </div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Not yet graded ({{ $pending->count() }})</h3>
  @if($pending->isEmpty())
    <p class="muted">Nothing waiting. </p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Candidate</th><th>Submitted</th><th>Answers to mark</th><th></th></tr></thead>
      <tbody>
        @foreach($pending as $session)
          <tr>
            <td data-label="Candidate">{{ $label($session) }}</td>
            <td class="muted" data-label="Submitted">{{ $session->submitted_at?->format('d M H:i') }}</td>
            <td data-label="Answers to mark">
              <span class="badge badge-warn">{{ $session->responses->where('status', 'ungraded')->count() }}</span>
            </td>
            <td><a class="btn btn-primary btn-sm" href="{{ route('school.cbt.grader.show', ['school' => $school->slug, 'sessionId' => $session->id]) }}">Mark</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Graded ({{ $done->count() }})</h3>
  @if($done->isEmpty())
    <p class="muted">Nothing marked yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Candidate</th><th>Submitted</th><th>Score</th><th></th></tr></thead>
      <tbody>
        @foreach($done as $session)
          <tr>
            <td data-label="Candidate">{{ $label($session) }}</td>
            <td class="muted" data-label="Submitted">{{ $session->submitted_at?->format('d M H:i') }}</td>
            <td class="muted" data-label="Score">{{ $session->total_score ?? '—' }} / {{ $session->max_score }}</td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.grader.show', ['school' => $school->slug, 'sessionId' => $session->id]) }}">Review</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
