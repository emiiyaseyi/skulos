@extends('layouts.app', ['portal' => 'school', 'pageTitle' => 'Clubs'])
@section('title', 'Clubs')
@section('heading', 'Clubs')
@section('crumb', 'School clubs and who belongs to them')
@section('content')

<div class="card" style="margin-bottom:1.2rem">
  <h3>Show clubs on profiles and report cards</h3>
  <p class="muted" style="margin-bottom:.8rem">
    Clubs are optional. While this is off, no club field appears on a student profile or report
    card, and nothing already assigned is lost.
  </p>
  <form method="POST" action="{{ route('school.clubs.toggle-enabled', ['school' => $school->slug]) }}">
    @csrf
    <input type="hidden" name="enabled" value="{{ $clubsEnabled ? 0 : 1 }}" />
    <button class="btn {{ $clubsEnabled ? 'btn-ghost' : 'btn-primary' }} btn-sm" type="submit">
      {{ $clubsEnabled ? 'Turn clubs off' : 'Turn clubs on' }}
    </button>
    <span class="muted" style="margin-left:.6rem">
      Currently {{ $clubsEnabled ? 'shown' : 'hidden' }}.
    </span>
  </form>
</div>

@if($clubs->isEmpty())
  <div class="card" style="margin-bottom:1.2rem">
    <h3>Start with the standard clubs</h3>
    <p class="muted" style="margin-bottom:.8rem">
      Adds {{ count($defaults) }} commonly run clubs in one click. You can rename, retire or remove
      any of them afterwards, and add your own.
    </p>
    <form method="POST" action="{{ route('school.clubs.apply-defaults', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Add the standard clubs</button>
    </form>
  </div>
@endif

<div class="grid cols-2" style="gap:1.2rem;margin-bottom:1.2rem">
  <div class="card">
    <h3>Add a club</h3>
    <form method="POST" action="{{ route('school.clubs.store', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="name">Club name</label>
        <input id="name" name="name" required maxlength="100" placeholder="e.g. Press Club" />
      </div>
      <div class="field">
        <label for="description">Description</label>
        <input id="description" name="description" maxlength="255" placeholder="Optional" />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Add club</button>
    </form>
  </div>

  <div class="card">
    <h3>Assign members</h3>
    <p class="muted" style="margin-bottom:.8rem">
      {{ $unassignedStudents }} active student(s) have no club yet.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
      <a class="btn btn-ghost btn-sm" href="{{ route('school.clubs.assign-form', ['school' => $school->slug]) }}">Assign one by one</a>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.clubs.bulk-template', ['school' => $school->slug]) }}">Download list</a>
      <form method="POST" action="{{ route('school.clubs.distribute', ['school' => $school->slug]) }}" style="display:inline">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit">Auto assign everyone</button>
      </form>
    </div>
    <form method="POST" action="{{ route('school.clubs.bulk-upload', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="margin-top:.9rem">
      @csrf
      <div class="field" style="margin-bottom:.5rem">
        <label for="file">Upload the completed list</label>
        <input id="file" name="file" type="file" accept=".csv,text/csv" required />
        <div class="hint">Only the club column is read. A blank cell leaves that student unchanged.</div>
      </div>
      <button class="btn btn-ghost btn-sm" type="submit">Upload</button>
    </form>
  </div>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Clubs</h3>
  @if($clubs->isEmpty())
    <p class="muted">No clubs yet.</p>
  @else
    <form method="POST" action="{{ route('school.clubs.bulk-action', ['school' => $school->slug]) }}" id="clubBulk">@csrf</form>
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr>
            <th style="width:2.2rem"><input type="checkbox" id="club-select-all" aria-label="Select all clubs" /></th>
            <th>Club</th><th>Students</th><th>Staff</th><th>Parents</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($clubs as $club)
            <tr>
              <td><input type="checkbox" name="club_ids[]" value="{{ $club->id }}" form="clubBulk" aria-label="Select {{ $club->name }}" /></td>
              <td data-label="Club">
                <a href="{{ route('school.clubs.show', ['school' => $school->slug, 'clubId' => $club->id]) }}">{{ $club->name }}</a>
                @if($club->description)<div class="muted">{{ $club->description }}</div>@endif
              </td>
              <td data-label="Students" class="muted">{{ $club->students_count }}</td>
              <td data-label="Staff" class="muted">{{ $club->staff_count }}</td>
              <td data-label="Parents" class="muted">{{ $club->guardian_members_count }}</td>
              <td data-label="Status">
                @if($club->is_active)<span class="badge badge-ok">Active</span>@else<span class="badge badge-warn">Retired</span>@endif
              </td>
              <td>
                <form method="POST" action="{{ route('school.clubs.toggle-active', ['school' => $school->slug, 'clubId' => $club->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $club->is_active ? 'Retire' : 'Reactivate' }}</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn btn-ghost btn-sm" type="submit" name="action" value="retire" form="clubBulk">Retire selected</button>
      <button class="btn btn-ghost btn-sm" type="submit" name="action" value="reactivate" form="clubBulk">Reactivate selected</button>
      <button class="btn btn-ghost btn-sm" type="submit" name="action" value="delete" form="clubBulk"
              onclick="return confirm('Remove the selected club(s)? Members are unassigned, not deleted.')">Delete selected</button>
    </div>
  @endif
</div>

<script>
  document.getElementById('club-select-all')?.addEventListener('change', function () {
    document.querySelectorAll('input[name="club_ids[]"]').forEach(function (box) { box.checked = this.checked; }, this);
  });
  document.getElementById('clubBulk')?.addEventListener('submit', function (e) {
    if (!document.querySelector('input[name="club_ids[]"]:checked')) {
      e.preventDefault();
      alert('Tick at least one club first.');
    }
  });
</script>
@endsection
