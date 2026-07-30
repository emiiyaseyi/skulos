@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $class->name])
@section('title', $class->name.' Parents')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $class->name }}</h1>
    <p class="subtitle">{{ $class->section?->name }} &middot; Students and guardians</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.parents.directory', ['school' => $school->slug]) }}">Back to Parent Portal</a>
</div>

@if($students->isEmpty())
  <div class="card"><p class="muted">No students in this class yet.</p></div>
@else
  <div class="card">
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Student</th><th>Class</th><th>Guardian(s)</th></tr></thead>
      <tbody>
        @foreach($students as $student)
          <tr>
            <td>
              <a href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}"><strong>{{ $student->fullName() }}</strong></a>
              <div class="muted">{{ $student->admission_number }}</div>
            </td>
            <td class="muted">{{ $student->currentClassArm?->label() }}</td>
            <td>
              @if(! $canSeeGuardianDetails)
                <span class="muted">Restricted</span>
              @elseif($student->guardians->isEmpty())
                <span class="muted">No guardian on record</span>
              @else
                @foreach($student->guardians as $guardian)
                  <div style="margin-bottom:.3rem">
                    <strong>{{ $guardian->name }}</strong>@if($guardian->is_primary)<span class="badge badge-ok" style="margin-left:.3rem">Primary</span>@endif
                    <div class="muted">{{ $guardian->relationship ?: 'Not set' }} &middot; {{ $guardian->phone ?: $guardian->email ?: 'Not set' }}</div>
                  </div>
                @endforeach
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif
@endsection
