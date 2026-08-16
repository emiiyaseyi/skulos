@extends('layouts.app', ['portal' => 'school', 'pageTitle' => 'Assign Clubs'])
@section('title', 'Assign Clubs')
@section('heading', 'Assign Clubs')
@section('crumb', 'Pick a club for each student, then save once')
@section('content')

<div class="card" style="margin-bottom:1.2rem">
  <form method="GET" class="grid cols-3" style="gap:1rem;align-items:end">
    <div class="field" style="margin:0">
      <label for="q">Search</label>
      <input id="q" name="q" value="{{ $search }}" placeholder="Name or admission number" />
    </div>
    <div class="field" style="margin:0">
      <label for="unassigned">Show</label>
      <select id="unassigned" name="unassigned">
        <option value="0" @selected(! $unassignedOnly)>Everyone</option>
        <option value="1" @selected($unassignedOnly)>Only students with no club</option>
      </select>
    </div>
    <div><button class="btn btn-primary btn-sm" type="submit">Apply</button></div>
  </form>
</div>

<div class="card">
  @if($clubs->isEmpty())
    <p class="muted">Add at least one active club before assigning students.</p>
  @elseif($students->isEmpty())
    <p class="muted">No students match this filter.</p>
  @else
    <form method="POST" action="{{ route('school.clubs.assign', ['school' => $school->slug]) }}">
      @csrf
      <div style="overflow-x:auto">
        <table class="table-stack">
          <thead><tr><th>Student</th><th>Admission no.</th><th>Class</th><th>Club</th></tr></thead>
          <tbody>
            @foreach($students as $student)
              <tr>
                <td data-label="Student">{{ $student->first_name }} {{ $student->last_name }}</td>
                <td data-label="Admission no." class="muted">{{ $student->admission_number }}</td>
                <td data-label="Class" class="muted">{{ $student->currentClassArm?->label() ?? '—' }}</td>
                <td data-label="Club">
                  <select name="clubs[{{ $student->id }}]">
                    <option value="">No club</option>
                    @foreach($clubs as $club)
                      <option value="{{ $club->id }}" @selected($student->club_id === $club->id)>{{ $club->name }}</option>
                    @endforeach
                  </select>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.6rem;align-items:center">
        <button class="btn btn-primary btn-sm" type="submit">Save this page</button>
        <span class="muted">Only students whose club actually changed are written.</span>
      </div>
    </form>
    <div style="margin-top:1rem">{{ $students->links() }}</div>
  @endif
</div>
@endsection
