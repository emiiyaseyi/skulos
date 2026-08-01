@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessments'])
@section('title', 'Assessments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessments</h1>
    <p class="subtitle">Timed assessments assigned to you.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card">
  @if($assessments->isEmpty())
    <p class="muted">Nothing assigned right now.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Title</th><th>Duration</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($assessments as $assessment)
          @php($session = $sessions->get($assessment->id))
          <tr>
            <td data-label="Title"><strong>{{ $assessment->title }}</strong></td>
            <td class="muted" data-label="Duration">{{ $assessment->duration_minutes }} min</td>
            <td data-label="Status">
              @if(! $session)
                <span class="badge badge-warn">Not started</span>
              @elseif($session->status === 'in_progress')
                <span class="badge badge-warn">In progress</span>
              @elseif($session->status === 'graded')
                <span class="badge badge-ok">Graded &middot; {{ $session->total_score }}/{{ $session->max_score }}</span>
              @else
                <span class="badge badge-ok">Submitted, awaiting grading</span>
              @endif
            </td>
            <td>
              @if(! $session)
                <form method="POST" action="{{ route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Start</button>
                </form>
              @elseif($session->status === 'in_progress')
                <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]) }}">Resume</a>
              @else
                <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.take.result', ['school' => $school->slug, 'sessionId' => $session->id]) }}">View result</a>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
