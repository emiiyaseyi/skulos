@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $class->name])
@section('title', $class->name.' Staff')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $class->name }}</h1>
    <p class="subtitle">{{ $class->section?->name }} &middot; Teaching staff</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.directory', ['school' => $school->slug]) }}">Back to Staff Portal</a>
</div>

@if($class->arms->isEmpty())
  <div class="card"><p class="muted">No arms set up for this class yet.</p></div>
@else
  @foreach($class->arms as $arm)
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.7rem">{{ $arm->label() }}</h3>
      <div class="grid cols-2" style="margin-bottom:.8rem">
        <div>
          <div class="muted" style="font-size:.8rem;margin-bottom:.3rem">Class teacher</div>
          @if($arm->classTeacher)
            <a href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $arm->classTeacher->id]) }}">{{ $arm->classTeacher->fullName() }}</a>
          @else
            <span class="muted">Not set</span>
          @endif
        </div>
        <div>
          <div class="muted" style="font-size:.8rem;margin-bottom:.3rem">Assistant class teacher</div>
          @if($arm->assistantTeacher)
            <a href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $arm->assistantTeacher->id]) }}">{{ $arm->assistantTeacher->fullName() }}</a>
          @else
            <span class="muted">Not set</span>
          @endif
        </div>
      </div>

      @php($assignments = $subjectTeachers->get($arm->id, collect()))
      <div class="muted" style="font-size:.8rem;margin-bottom:.4rem">Subject teachers</div>
      @if($assignments->isEmpty())
        <p class="muted" style="font-size:.85rem">No subject teachers assigned yet.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Subject</th><th>Teacher</th></tr></thead>
          <tbody>
            @foreach($assignments as $assignment)
              <tr>
                <td>{{ $assignment->subject?->name }}</td>
                <td>
                  @if($assignment->staff)
                    <a href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $assignment->staff->id]) }}">{{ $assignment->staff->fullName() }}</a>
                  @else
                    <span class="muted">Not set</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    </div>
  @endforeach
@endif
@endsection
