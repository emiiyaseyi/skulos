@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Proctors'])
@section('title', 'Proctors')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Proctors</h1>
    <p class="subtitle">Who may invigilate assessments and act on a live attempt.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.console.index', ['school' => $school->slug, 'tab' => 'proctors']) }}">Admin Backend</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a proctor</h3>
  <form method="POST" action="{{ route('school.cbt.people.proctors.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:.75rem">
      <div class="field">
        <label for="user_id">Member of staff</label>
        <select id="user_id" name="user_id" required>
          <option value="">Choose someone</option>
          @foreach($staff as $person)
            <option value="{{ $person->id }}">{{ $person->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="assessment_id">Scope</label>
        <select id="assessment_id" name="assessment_id">
          <option value="">Every assessment (school wide)</option>
          @foreach($assessments as $assessment)
            <option value="{{ $assessment->id }}">{{ $assessment->title }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add proctor</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Current proctors</h3>
  @if($proctors->isEmpty())
    <p class="muted">Nobody is assigned as a proctor yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Name</th><th>Scope</th><th></th></tr></thead>
      <tbody>
        @foreach($proctors as $proctor)
          <tr>
            <td data-label="Name">{{ $staffNames[$proctor->user_id] ?? 'Unknown' }}</td>
            <td data-label="Scope">
              @if($proctor->assessment_id)
                <span class="muted">{{ $proctor->assessment?->title }}</span>
              @else
                <span class="badge badge-ok">School wide</span>
              @endif
            </td>
            <td>
              <form method="POST" action="{{ route('school.cbt.people.proctors.destroy', ['school' => $school->slug, 'proctorId' => $proctor->id]) }}" onsubmit="return confirm('Remove this proctor?')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
