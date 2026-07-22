@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Search'])
@section('title', 'Search')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Search</h1>
    <p class="subtitle">Students, staff, and parents whose details match what you typed.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <form method="GET" action="{{ route('school.search.index', ['school' => $school->slug]) }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="Search students, staff, parents" autofocus style="max-width:28rem" />
  </form>
</div>

@if($q === '')
  <p class="muted">Type at least 2 characters to search.</p>
@elseif(mb_strlen($q) < 2)
  <p class="muted">Type at least 2 characters to search.</p>
@elseif($total === 0)
  <p class="muted">No matches for "{{ $q }}".</p>
@else
  @if($students->isNotEmpty())
    <div class="card" style="margin-bottom:1.25rem">
      <h3 style="margin-bottom:.8rem">Students</h3>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Admission No.</th><th>Name</th><th></th></tr></thead>
        <tbody>
          @foreach($students as $student)
            <tr>
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
              <td><strong>{{ $student->fullName() }}</strong></td>
              <td><a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">View</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
  @endif

  @if($staff->isNotEmpty())
    <div class="card" style="margin-bottom:1.25rem">
      <h3 style="margin-bottom:.8rem">Staff</h3>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Staff No.</th><th>Name</th><th>Position</th><th></th></tr></thead>
        <tbody>
          @foreach($staff as $member)
            <tr>
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $member->staff_id }}</td>
              <td><strong>{{ $member->fullName() }}</strong></td>
              <td class="muted">{{ $member->position }}</td>
              <td><a class="btn btn-ghost btn-sm" href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $member->id]) }}">View</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
  @endif

  @if($guardians->isNotEmpty())
    <div class="card">
      <h3 style="margin-bottom:.8rem">Parents / Guardians</h3>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Name</th><th>Contact</th><th>Student</th><th></th></tr></thead>
        <tbody>
          @foreach($guardians as $guardian)
            <tr>
              <td><strong>{{ $guardian->name }}</strong></td>
              <td class="muted">{{ $canSeeGuardianDetails ? ($guardian->email ?: $guardian->phone) : 'Restricted' }}</td>
              <td class="muted">{{ $guardian->student?->fullName() }}</td>
              <td>
                @if($guardian->student)
                  <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $guardian->student->id]) }}">View student</a>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
  @endif
@endif
@endsection
