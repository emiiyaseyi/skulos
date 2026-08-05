@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $student->fullName().'\'s Assessments'])
@section('title', $student->fullName().'\'s Assessments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $student->fullName() }}'s Assessments</h1>
    <p class="subtitle">{{ $student->currentClassArm?->label() ?? 'No class' }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.parent.index', ['school' => $school->slug]) }}">Back to all children</a>
  </div>
</div>

@if($rows->isEmpty())
  <div class="card">
    <p class="muted">No assessments assigned right now.</p>
  </div>
@else
  <div class="stack" style="gap:1rem">
    @foreach($rows as $row)
      @php($assessment = $row['assessment'])
      @php($session = $row['session'])
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.6rem">
          <div>
            <h3 style="margin-bottom:.2rem">{{ $assessment->displayName() }}</h3>
            <span class="muted" style="font-size:.85rem">{{ $assessment->duration_minutes }} minutes</span>
          </div>
          <div style="text-align:right">
            @if(! $session)
              <span class="badge badge-warn">Not started</span>
            @elseif($session->status === 'in_progress')
              <span class="badge badge-warn">In progress</span>
            @elseif($session->status === 'graded' && $row['scoreVisible'])
              <span class="badge badge-ok">Graded &middot; {{ $session->total_score }}/{{ $session->max_score }}</span>
              @if($row['passed'] !== null)
                <div style="margin-top:.3rem">
                  <span class="badge {{ $row['passed'] ? 'badge-ok' : 'badge-bad' }}">{{ $row['passed'] ? 'Passed' : 'Did not pass' }} &middot; pass mark {{ $assessment->pass_mark }}</span>
                </div>
              @endif
            @elseif($session->status === 'graded')
              <span class="badge badge-ok">Graded &middot; result not released yet</span>
            @else
              <span class="badge badge-ok">Submitted, awaiting grading</span>
            @endif
          </div>
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection
