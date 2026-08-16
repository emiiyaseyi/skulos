@extends('layouts.app', ['portal' => 'school', 'pageTitle' => $club->name])
@section('title', $club->name)
@section('heading', $club->name)
@section('crumb', $club->description ?: 'Club members')
@section('content')

<div class="card" style="margin-bottom:1.2rem">
  <h3>Club details</h3>
  <form method="POST" action="{{ route('school.clubs.update', ['school' => $school->slug, 'clubId' => $club->id]) }}">
    @csrf
    <div class="grid cols-2" style="gap:1rem">
      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" value="{{ $club->name }}" required maxlength="100" />
      </div>
      <div class="field">
        <label for="description">Description</label>
        <input id="description" name="description" value="{{ $club->description }}" maxlength="255" />
      </div>
      <div class="field">
        <label for="patron_staff_id">Patron</label>
        <select id="patron_staff_id" name="patron_staff_id">
          <option value="">Not set</option>
          @foreach($allStaff as $s)
            <option value="{{ $s->id }}" @selected($club->patron_staff_id === $s->id)>{{ $s->first_name }} {{ $s->last_name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="assistant_patron_staff_id">Assistant patron</label>
        <select id="assistant_patron_staff_id" name="assistant_patron_staff_id">
          <option value="">Not set</option>
          @foreach($allStaff as $s)
            <option value="{{ $s->id }}" @selected($club->assistant_patron_staff_id === $s->id)>{{ $s->first_name }} {{ $s->last_name }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.2rem">
  <h3 style="margin-bottom:.6rem">Students ({{ $students->count() }})</h3>
  @if($students->isEmpty())
    <p class="muted">No students in this club yet.</p>
  @else
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Name</th><th>Admission no.</th><th>Class</th></tr></thead>
        <tbody>
          @foreach($students as $student)
            <tr>
              <td data-label="Name">{{ $student->first_name }} {{ $student->last_name }}</td>
              <td data-label="Admission no." class="muted">{{ $student->admission_number }}</td>
              <td data-label="Class" class="muted">{{ $student->currentClassArm?->label() ?? '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>

<div class="grid cols-2" style="gap:1.2rem">
  <div class="card">
    <h3 style="margin-bottom:.6rem">Staff ({{ $staffMembers->count() }})</h3>
    @if($staffMembers->isEmpty())
      <p class="muted">No staff in this club yet.</p>
    @else
      <ul style="margin:0;padding-left:1.1rem">
        @foreach($staffMembers as $s)<li>{{ $s->first_name }} {{ $s->last_name }}</li>@endforeach
      </ul>
    @endif
  </div>
  <div class="card">
    <h3 style="margin-bottom:.6rem">Parents ({{ $guardians->count() }})</h3>
    @if($guardians->isEmpty())
      <p class="muted">No parents in this club yet.</p>
    @else
      <ul style="margin:0;padding-left:1.1rem">
        @foreach($guardians as $g)<li>{{ $g->name }}</li>@endforeach
      </ul>
    @endif
  </div>
</div>
@endsection
