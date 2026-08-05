@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $student->fullName().'\'s Assignments'])
@section('title', $student->fullName().'\'s Assignments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $student->fullName() }}'s Assignments</h1>
    <p class="subtitle">{{ $student->currentClassArm?->label() ?? 'No class' }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.assignments.mine', ['school' => $school->slug]) }}">Back to all children</a>
  </div>
</div>

@if($rows->isEmpty())
  <div class="card">
    <p class="muted">No assignments posted right now.</p>
  </div>
@else
  <div class="stack" style="gap:1rem">
    @foreach($rows as $row)
      @php($assignment = $row['assignment'])
      @php($submission = $row['submission'])
      <a href="{{ route('school.assignments.show', ['school' => $school->slug, 'assignmentId' => $assignment->id]) }}" class="card" style="text-decoration:none;color:inherit;display:block">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.6rem">
          <div>
            <h3 style="margin-bottom:.2rem">{{ $assignment->title }}</h3>
            <span class="muted" style="font-size:.85rem">{{ $assignment->subject?->name }} &middot; {{ $assignment->due_on ? 'Due '.$assignment->due_on->format('d M Y') : 'No due date' }}</span>
          </div>
          @if($submission)
            <span class="badge badge-ok">Submitted {{ $submission->submitted_at?->format('d M Y') }}</span>
          @else
            <span class="badge badge-warn">Not submitted</span>
          @endif
        </div>
      </a>
    @endforeach
  </div>
@endif
@endsection
