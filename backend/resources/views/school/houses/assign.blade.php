@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assign Houses'])
@section('title', 'Assign Houses')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assign Houses</h1>
    <p class="subtitle">Pick a house for as many students as you like, then save once at the bottom.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.index', ['school' => $school->slug]) }}">Back to Houses</a>
</div>

<div class="card">
  <form method="GET" style="margin-bottom:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or admission number" style="max-width:22rem" />
    <label style="font-weight:500;display:flex;gap:.4rem;align-items:center">
      <input type="checkbox" name="unassigned" value="1" @checked($unassignedOnly) onchange="this.form.requestSubmit()" />
      Show only students without a house
    </label>
  </form>

  @if($students->isEmpty())
    <p class="muted">
      @if($unassignedOnly) Every active student already has a house.
      @elseif($search) No students match your search.
      @else No active students yet.
      @endif
    </p>
  @else
    <form method="POST" action="{{ route('school.houses.assign-bulk', ['school' => $school->slug, 'q' => $search, 'unassigned' => $unassignedOnly ? 1 : null, 'page' => $students->currentPage()]) }}">
      @csrf
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
                <select name="assignments[{{ $student->id }}]">
                  <option value="">No house</option>
                  @foreach($houses as $house)
                    <option value="{{ $house->id }}" @selected($student->house_id === $house->id)>{{ $house->name }}</option>
                  @endforeach
                </select>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <button class="btn btn-primary" type="submit" style="margin-top:1.2rem">Save this page</button>
    </form>
    <div style="margin-top:1rem">{{ $students->links() }}</div>
  @endif
</div>
@endsection
