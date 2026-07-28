{{-- Singular for a parent with exactly one child (user directive
     2026-07-28: "for parents that have one child, it should show as My
     child and not my children"). --}}
@php($myChildrenLabel = $roleSlug === 'parent' ? ($familyStudents->count() === 1 ? 'My Child' : 'My Children') : 'My Report')
@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $myChildrenLabel])
@section('title', $myChildrenLabel)
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $myChildrenLabel }}</h1>
    <p class="subtitle">{{ $roleSlug === 'parent' ? 'View each child\'s profile, class, and academic report.' : 'Your class, house, and academic report.' }}</p>
  </div>
</div>

@if($familyStudents->isEmpty())
  <div class="card">
    <p class="muted" style="margin-bottom:0">
      @if($roleSlug === 'parent')
        No child is linked to your account yet. Contact the school office if this seems wrong.
      @else
        Your student record has not been linked to this login yet. Contact the school office if this seems wrong.
      @endif
    </p>
  </div>
@else
  <div class="grid {{ $familyStudents->count() > 1 ? 'cols-2' : '' }}" style="{{ $familyStudents->count() === 1 ? 'max-width:22rem;margin:0 auto' : '' }}">
    @foreach($familyStudents as $student)
      <div class="card" style="text-align:center">
        <div style="display:flex;justify-content:center;margin-bottom:.8rem">
          <x-student-avatar :student="$student" size="7rem" />
        </div>
        <h3 style="margin-bottom:.15rem">{{ $student->fullName() }}</h3>
        <p class="muted" style="margin-bottom:1rem">{{ $student->currentClassArm?->label() ?? 'Class not set' }}</p>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">View Profile</a>
      </div>
    @endforeach
  </div>
@endif
@endsection
