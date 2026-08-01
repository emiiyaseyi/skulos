@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $session->assessment->title])
@section('title', $session->assessment->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $session->assessment->title }}</h1>
    <p class="subtitle">
      @if($session->status === 'graded')
        Score: {{ $session->total_score }} / {{ $session->max_score }}
      @else
        Submitted, awaiting grading.
      @endif
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.take.available', ['school' => $school->slug]) }}">All assessments</a>
</div>

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
