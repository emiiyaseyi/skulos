@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Academic Report'])
@section('title', 'Academic Report')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Academic report</h1>
    <p class="subtitle">Choose which child to view.</p>
  </div>
</div>

<div class="card">
  @if($students->isEmpty())
    <p class="muted">No child is linked to your account yet.</p>
  @else
    <div class="stack" style="gap:.6rem">
      @foreach($students as $s)
        <a class="btn btn-ghost" style="justify-content:flex-start" href="{{ route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $s->id]) }}">
          {{ $s->fullName() }} &middot; {{ $s->currentClassArm?->label() ?? 'No class set' }}
        </a>
      @endforeach
    </div>
  @endif
</div>
@endsection
