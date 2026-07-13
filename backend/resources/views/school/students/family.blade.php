@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $roleSlug === 'parent' ? 'My Children' : 'My Report'])
@section('title', $roleSlug === 'parent' ? 'My Children' : 'My Report')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $roleSlug === 'parent' ? 'My Children' : 'My Report' }}</h1>
    <p class="subtitle">{{ $roleSlug === 'parent' ? 'View each child\'s profile, class, and academic report.' : 'Your class, house, and academic report.' }}</p>
  </div>
</div>

<div class="card">
  @if($familyStudents->isEmpty())
    <p class="muted">
      @if($roleSlug === 'parent')
        No child is linked to your account yet. Contact the school office if this seems wrong.
      @else
        Your student record has not been linked to this login yet. Contact the school office if this seems wrong.
      @endif
    </p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Admission No.</th><th>Class</th><th></th></tr></thead>
      <tbody>
        @foreach($familyStudents as $student)
          <tr>
            <td><strong>{{ $student->fullName() }}</strong></td>
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
            <td class="muted">{{ $student->currentClassArm?->label() ?? 'Not set' }}</td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">View report</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
