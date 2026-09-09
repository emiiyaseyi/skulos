{{--
  Read-only clubs view for a scoped teacher (class teacher, class
  assistant, or a plain "teacher" role) -- see ClubController::index()
  for why this exists as its own template rather than a permission
  check bolted onto the full admin one. Only clubs with a student from
  the teacher's own class(es) are shown; each club lists only that
  class's own members, never the whole school's.
--}}
@extends('layouts.app', ['portal' => 'school', 'pageTitle' => 'Clubs'])
@section('title', 'Clubs')
@section('heading', 'Clubs')
@section('crumb', "Your class's club distribution")
@section('content')

@if(! $clubsEnabled)
  <div class="card" style="margin-bottom:1.2rem">
    <p class="muted">Clubs are currently turned off for this school, so nothing is assigned yet.</p>
  </div>
@endif

<div class="card">
  <h3 style="margin-bottom:.4rem">Your class's clubs</h3>
  <p class="muted" style="margin-bottom:.8rem">Which of your own students belong to which club. A club with none of your students in it is not shown.</p>

  @if($clubs->isEmpty())
    <p class="muted">None of your students are in a club yet.</p>
  @else
    <div class="grid cols-2" style="gap:1rem">
      @foreach($clubs as $club)
        <div class="card" style="background:var(--bg)">
          <h4 style="margin-bottom:.3rem">{{ $club->name }}</h4>
          @if($club->description)<p class="muted" style="margin-bottom:.5rem">{{ $club->description }}</p>@endif
          <p class="muted" style="margin-bottom:.5rem">{{ $club->students->count() }} of your student(s)</p>
          <ul style="margin:0;padding-left:1.1rem">
            @foreach($club->students as $student)
              <li>{{ $student->fullName() }}</li>
            @endforeach
          </ul>
        </div>
      @endforeach
    </div>
  @endif
</div>
@endsection
