@extends('layouts.app', ['portal' => $portal ?? 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessments'])
@section('title', 'Assessments')
{{-- See result.blade.php's own comment: the admissions portal has no
     nav-admissions partial, every applicant-facing view goes bare. --}}
@if(($portal ?? 'school') === 'admissions')
  @section('bare', true)
@endif

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
  @if($rows->isEmpty())
    <p class="muted">Nothing assigned right now.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Title</th><th>Duration</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($rows as $row)
          @php($assessment = $row['assessment'])
          @php($session = $row['session'])
          <tr>
            <td data-label="Title"><strong>{{ $assessment->displayName() }}</strong></td>
            <td class="muted" data-label="Duration">{{ $assessment->duration_minutes }} min</td>
            <td data-label="Status">
              @if(! $session)
                <span class="badge badge-warn">Not started</span>
              @elseif($session->status === 'in_progress')
                <span class="badge badge-warn">In progress</span>
              @elseif($session->status === 'graded' && $row['scoreVisible'])
                <span class="badge badge-ok">Graded &middot; {{ $session->total_score }}/{{ $session->max_score }}</span>
              @elseif($session->status === 'graded')
                <span class="badge badge-ok">Graded &middot; result not released yet</span>
              @else
                <span class="badge badge-ok">Submitted, awaiting grading</span>
              @endif
            </td>
            <td>
              @if(! $session)
                <form method="POST" action="{{ $row['startUrl'] }}">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Start</button>
                </form>
              @elseif($session->status === 'in_progress')
                <a class="btn btn-primary btn-sm" href="{{ $row['resumeUrl'] }}">Resume</a>
              @else
                <a class="btn btn-ghost btn-sm" href="{{ $row['resultUrl'] }}">View result</a>
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
