@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Parents'])
@section('title', 'Parents')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Parents</h1>
    <p class="subtitle">{{ number_format($total) }} guardian record(s) on file.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.parents.directory', ['school' => $school->slug]) }}">Browse by class</a>
</div>

@if(! $canSeeGuardianDetails)
  <div class="card" style="margin-bottom:1.1rem"><p class="muted">Guardian details are restricted for your role. Ask the school admin if you need access.</p></div>
@else

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name, email, phone, or student" />
  </form>
</div>

<div class="card">
  @if($guardians->isEmpty())
    <p class="muted">No guardians match your search.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Relationship</th><th>Contact</th><th>Student</th><th>Class</th><th></th></tr></thead>
      <tbody>
        @foreach($guardians as $guardian)
          <tr>
            <td><strong>{{ $guardian->name }}</strong></td>
            <td class="muted">{{ $guardian->relationship ?: 'Not set' }}</td>
            <td class="muted">{{ $guardian->email ?: $guardian->phone ?: 'Not set' }}</td>
            <td class="muted">{{ $guardian->student?->fullName() ?? 'Removed student' }}</td>
            <td class="muted">{{ $guardian->student?->currentClassArm?->label() ?? 'Not set' }}</td>
            <td>
              @if($guardian->student)
                <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $guardian->student_id]) }}">View</a>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($guardians->hasPages())
      <div style="margin-top:1rem">{{ $guardians->links() }}</div>
    @endif
  @endif
</div>
@endif
@endsection
