@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $session->assessment->title])
@section('title', $session->assessment->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $session->assessment->title }}</h1>
    <p class="subtitle">
      @if($session->status === 'graded' && $resultsVisible)
        Score: {{ $session->total_score }} / {{ $session->max_score }}
      @elseif($session->status === 'graded')
        Grading is complete, but your result is not available to view yet.
      @else
        Submitted, awaiting grading.
      @endif
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.take.available', ['school' => $school->slug]) }}">All assessments</a>
</div>

@if($resultsVisible && $passed !== null)
  {{-- The admin's own configured wording for a pass/fail crossing —
       e.g. an admission assessment telling a passing applicant they
       have moved to the next stage of the application process. Falls
       back to a plain generic line when left blank. --}}
  <div class="card" style="margin-bottom:1rem;border-left:4px solid {{ $passed ? 'var(--ok)' : 'var(--bad)' }}">
    <h3 style="margin-bottom:.4rem">{{ $passed ? 'You passed' : 'You did not pass' }}</h3>
    <p>
      @if($passed)
        {{ $session->assessment->pass_message ?: 'You have met the pass mark for this assessment.' }}
      @else
        {{ $session->assessment->fail_message ?: 'You did not meet the pass mark for this assessment.' }}
      @endif
    </p>
  </div>
@endif

<div class="card">
  <p class="muted">
    @if($session->status === 'graded')
      Every question in this assessment has been graded.
    @elseif($session->status === 'auto_submitted')
      Your time ran out and this attempt was submitted automatically. Objective questions are graded; any written answers are still awaiting review.
    @else
      Objective questions are graded; any written answers are still awaiting review.
    @endif
  </p>
</div>
@endsection
