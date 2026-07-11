@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Houses'])
@section('title', 'Houses')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Houses</h1>
    <p class="subtitle">Every student belongs to a house. Assign them one by one, or upload a filled in CSV.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.assign-form', ['school' => $school->slug]) }}">Assign students</a>
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Your houses</h3>
  @if($houses->isEmpty())
    <p class="muted">No houses yet. Load the defaults below, or add your own.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Members</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($houses as $house)
          <tr>
            <td><strong>{{ $house->name }}</strong></td>
            <td class="muted">{{ $house->students_count }}</td>
            <td><span class="badge {{ $house->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $house->is_active ? 'Active' : 'Retired' }}</span></td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.houses.show', ['school' => $school->slug, 'houseId' => $house->id]) }}">View</a>
              @if($canManage)
                <form method="POST" action="{{ route('school.houses.toggle-active', ['school' => $school->slug, 'houseId' => $house->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $house->is_active ? 'Retire' : 'Reactivate' }}</button>
                </form>
                <form method="POST" action="{{ route('school.houses.destroy', ['school' => $school->slug, 'houseId' => $house->id]) }}" style="display:inline" onsubmit="return confirm('Remove {{ $house->name }}? Its members will be unassigned, not deleted.')">
                  @csrf @method('DELETE')
                  <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

@if($canManage)
<div class="grid cols-2" style="align-items:start;margin-bottom:1.25rem">
  <div class="card">
    <h3 style="margin-bottom:.4rem">Load default houses</h3>
    <p class="muted" style="margin-bottom:.8rem">Most schools run 4. You can rename or remove any of them afterwards.</p>
    <form method="POST" action="{{ route('school.houses.apply-defaults', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Load default houses</button>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Add a house</h3>
    <form method="POST" action="{{ route('school.houses.store', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="h_name">House name</label>
        <input id="h_name" name="name" placeholder="e.g. Red House" required />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Add house</button>
    </form>
  </div>
</div>
@endif

<div class="card">
  <h3 style="margin-bottom:.4rem">Bulk assign by CSV</h3>
  <p class="muted" style="margin-bottom:.8rem">Download every current student with their admission number, class, and current house. Fill in or change the house column, then upload it back. Blank house cells leave that student's house untouched.</p>
  <a class="btn btn-ghost" href="{{ route('school.houses.bulk-template', ['school' => $school->slug]) }}">Download CSV</a>

  @if($canManage)
    <form method="POST" action="{{ route('school.houses.bulk-assign', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="margin-top:1rem;display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
      @csrf
      <div class="field" style="margin-bottom:0;flex:1;min-width:14rem">
        <label for="file">Filled in CSV</label>
        <input id="file" name="file" type="file" accept=".csv,text/csv" required />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Upload</button>
    </form>
  @endif
</div>
@endsection
