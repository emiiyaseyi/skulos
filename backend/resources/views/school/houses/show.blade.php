@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $house->name])
@section('title', $house->name)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $house->name }}</h1>
    <p class="subtitle">{{ $house->students_count }} member(s). <span class="badge {{ $house->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $house->is_active ? 'Active' : 'Retired' }}</span></p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.download-members', ['school' => $school->slug, 'houseId' => $house->id]) }}">Download members</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.index', ['school' => $school->slug]) }}">Back to Houses</a>
  </div>
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.25rem;max-width:32rem">
  <h3 style="margin-bottom:.8rem">Rename or recolour</h3>
  <form method="POST" action="{{ route('school.houses.update', ['school' => $school->slug, 'houseId' => $house->id]) }}" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    @csrf
    <div class="field" style="margin-bottom:0;flex:1;min-width:10rem">
      <label for="h_name">Name</label>
      <input id="h_name" name="name" value="{{ $house->name }}" required />
    </div>
    <div class="field" style="margin-bottom:0;min-width:8rem">
      <label for="h_color">Colour (optional)</label>
      <input id="h_color" name="color" value="{{ $house->color }}" placeholder="e.g. Red" />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>

  <div style="border-top:1px solid var(--line);margin-top:1.2rem;padding-top:1rem">
    <form method="POST" action="{{ route('school.houses.toggle-active', ['school' => $school->slug, 'houseId' => $house->id]) }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit">{{ $house->is_active ? 'Retire this house' : 'Reactivate this house' }}</button>
    </form>
    <p class="hint" style="margin-top:.4rem">{{ $house->is_active ? 'Retiring keeps its history, its current members stay assigned, but it drops out of every assignment picker.' : 'Reactivating makes it assignable again.' }}</p>
  </div>
</div>
@endif

<div class="card">
  <h3 style="margin-bottom:.8rem">Members</h3>
  @if($students->isEmpty())
    <p class="muted">No students in this house yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Admission No.</th><th>Name</th><th>Class</th></tr></thead>
      <tbody>
        @foreach($students as $student)
          <tr>
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
            <td><strong>{{ $student->fullName() }}</strong></td>
            <td class="muted">{{ $student->currentClassArm?->label() ?? 'Not set' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
