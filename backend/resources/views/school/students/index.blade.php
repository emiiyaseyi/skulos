@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Students'])
@section('title', 'Students')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Students</h1>
    <p class="subtitle">{{ number_format($total) }} on record.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canCreate)
      <a class="btn btn-primary btn-sm" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Add student</a>
    @endif
    @if($canImport)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.students.import', ['school' => $school->slug]) }}">Bulk import</a>
    @endif
  </div>
</div>

<div class="card">
  <form method="GET" style="margin-bottom:1rem;max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or admission number" />
  </form>

  @if($students->isEmpty())
    <p class="muted">{{ $search ? 'No students match your search.' : 'No students yet. Add one, or use bulk import to add them from a CSV.' }}</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Admission No.</th><th>Name</th><th>Gender</th><th>Status</th></tr></thead>
      <tbody>
        @foreach($students as $student)
          <tr class="row-link" onclick="window.location='{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}'" style="cursor:pointer">
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
            <td><strong>{{ $student->fullName() }}</strong></td>
            <td>{{ $student->gender ? ucfirst($student->gender) : 'Not set' }}</td>
            <td><span class="badge {{ $student->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($student->status) }}</span></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $students->links() }}</div>
  @endif
</div>
@endsection
