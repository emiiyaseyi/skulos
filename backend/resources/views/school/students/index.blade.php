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

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or admission number" />
  </form>
</div>

@if($search)
  <div class="card">
    @if($students->isEmpty())
      <p class="muted">No students match your search.</p>
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
@else
  @if($sections->isEmpty())
    <div class="card"><p class="muted">No classes yet. Set up your class structure under Classes &amp; Subjects, then students grouped by class will show here.</p></div>
  @else
    @foreach($sections as $section)
      <div class="card" style="margin-bottom:1.1rem">
        <h3 style="margin-bottom:.7rem">{{ $section->name }}</h3>
        @if($section->classes->isEmpty())
          <p class="muted">No classes in this section yet.</p>
        @else
          <div style="overflow-x:auto">
          <table>
            <thead><tr><th>Class</th><th>Students</th><th></th></tr></thead>
            <tbody>
              @foreach($section->classes as $class)
                @php($classTotal = $class->arms->sum(fn($arm) => $classCounts[$arm->id] ?? 0))
                <tr>
                  <td>
                    <strong>{{ $class->name }}</strong>
                    @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                  </td>
                  <td class="muted">{{ $classTotal }}</td>
                  <td><a class="btn btn-ghost btn-sm" href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </div>
    @endforeach
  @endif

  @if($unassigned->isNotEmpty())
    <div class="card">
      <h3 style="margin-bottom:.7rem">No class assigned</h3>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Admission No.</th><th>Name</th><th></th></tr></thead>
        <tbody>
          @foreach($unassigned as $student)
            <tr class="row-link" onclick="window.location='{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}'" style="cursor:pointer">
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
              <td><strong>{{ $student->fullName() }}</strong></td>
              <td><a class="btn btn-ghost btn-sm" href="{{ route('school.students.edit', ['school' => $school->slug, 'studentId' => $student->id]) }}">Assign class</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
  @endif
@endif
@endsection
