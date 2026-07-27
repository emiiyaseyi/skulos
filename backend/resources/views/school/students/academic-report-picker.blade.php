@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Academic Report'])
@section('title', 'Academic Report')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Academic report</h1>
    <p class="subtitle">Choose which child to view.</p>
  </div>
</div>

@if($students->isEmpty())
  <div class="card">
    <p class="muted" style="margin-bottom:0">No child is linked to your account yet.</p>
  </div>
@else
  <div class="grid {{ $students->count() > 1 ? 'cols-2' : '' }}" style="{{ $students->count() === 1 ? 'max-width:22rem;margin:0 auto' : '' }}">
    @foreach($students as $s)
      <a class="card" style="text-align:center;text-decoration:none;color:inherit" href="{{ route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $s->id]) }}">
        <div style="display:flex;justify-content:center;margin-bottom:.8rem">
          <x-student-avatar :student="$s" size="7rem" />
        </div>
        <h3 style="margin-bottom:.15rem">{{ $s->fullName() }}</h3>
        <p class="muted" style="margin-bottom:0">{{ $s->currentClassArm?->label() ?? 'No class set' }}</p>
      </a>
    @endforeach
  </div>
@endif
@endsection
