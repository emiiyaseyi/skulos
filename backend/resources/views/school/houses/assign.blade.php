@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assign Houses'])
@section('title', 'Assign Houses')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assign Houses</h1>
    <p class="subtitle">Pick a house for each student. Saved as soon as you choose one.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.index', ['school' => $school->slug]) }}">Back to Houses</a>
</div>

<div class="card">
  <form method="GET" style="margin-bottom:1rem;max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or admission number" />
  </form>

  @if($students->isEmpty())
    <p class="muted">{{ $search ? 'No students match your search.' : 'No active students yet.' }}</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Admission No.</th><th>Name</th><th>Class</th><th>House</th></tr></thead>
      <tbody>
        @foreach($students as $student)
          <tr>
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
            <td><strong>{{ $student->fullName() }}</strong></td>
            <td class="muted">{{ $student->currentClassArm?->label() ?? 'Not set' }}</td>
            <td>
              <form method="POST" action="{{ route('school.houses.assign', ['school' => $school->slug, 'studentId' => $student->id]) }}">
                @csrf
                <select name="house_id" onchange="this.form.requestSubmit()">
                  <option value="">No house</option>
                  @foreach($houses as $house)
                    <option value="{{ $house->id }}" @selected($student->house_id === $house->id)>{{ $house->name }}</option>
                  @endforeach
                </select>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $students->links() }}</div>
  @endif
</div>
@endsection
