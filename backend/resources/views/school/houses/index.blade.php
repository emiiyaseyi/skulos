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
    @if($canManage)
      <form id="bulkHousesForm" method="POST" action="{{ route('school.houses.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="house-select-all" aria-label="Select all houses" /></th>@endif
        <th>Name</th><th>Members</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($houses as $house)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkHousesForm" class="house-row-check" name="house_ids[]" value="{{ $house->id }}" aria-label="Select {{ $house->name }}" /></td>
            @endif
            <td data-label="Name"><strong>{{ $house->name }}</strong></td>
            <td class="muted" data-label="Members">{{ $house->students_count }}</td>
            <td data-label="Status"><span class="badge {{ $house->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $house->is_active ? 'Active' : 'Retired' }}</span></td>
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

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of houses above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkHousesForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="reactivate">Reactivate selected</button>
          <button form="bulkHousesForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="retire">Retire selected</button>
          <button form="bulkHousesForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected houses? Their members will be unassigned, not deleted.')">Delete selected</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('house-select-all');
          var boxes = document.querySelectorAll('.house-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkHousesForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one house first.'); }
          });
        })();
      </script>
    @endif
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

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Distribute students, staff and parents</h3>
  <p class="muted" style="margin-bottom:.8rem">Spread every student, staff member, and parent who is not yet in a house evenly across your active houses. Anyone already assigned is left untouched.</p>
  <form method="POST" action="{{ route('school.houses.distribute-members', ['school' => $school->slug]) }}">
    @csrf
    <button class="btn btn-primary btn-sm" type="submit">Distribute students, staff and parents</button>
  </form>
</div>
@endif

<div class="grid cols-3" style="align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.4rem">Students, by CSV</h3>
    <p class="muted" style="margin-bottom:.8rem">Download every current student with their admission number, class, and current house. Fill in or change the house column, then upload it back. Blank house cells leave that student's house untouched.</p>
    <a class="btn btn-ghost" href="{{ route('school.houses.bulk-template', ['school' => $school->slug]) }}">Download CSV</a>

    @if($canManage)
      <form method="POST" action="{{ route('school.houses.bulk-assign', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="margin-top:1rem;display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div class="field" style="margin-bottom:0;flex:1;min-width:12rem">
          <label for="file">Filled in CSV</label>
          <input id="file" name="file" type="file" accept=".csv,text/csv" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </form>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.4rem">Staff, by CSV</h3>
    <p class="muted" style="margin-bottom:.8rem">Same round trip, for staff: staff id, name, and current house. Blank house cells leave that staff member's house untouched.</p>
    <a class="btn btn-ghost" href="{{ route('school.houses.staff-bulk-template', ['school' => $school->slug]) }}">Download CSV</a>

    @if($canManage)
      <form method="POST" action="{{ route('school.houses.staff-bulk-assign', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="margin-top:1rem;display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div class="field" style="margin-bottom:0;flex:1;min-width:12rem">
          <label for="staff_file">Filled in CSV</label>
          <input id="staff_file" name="file" type="file" accept=".csv,text/csv" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </form>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.4rem">Parents, by CSV</h3>
    <p class="muted" style="margin-bottom:.8rem">Same round trip, for parents: the child's admission number, the parent's name, and current house. Blank house cells leave that parent's house untouched.</p>
    <a class="btn btn-ghost" href="{{ route('school.houses.parents-bulk-template', ['school' => $school->slug]) }}">Download CSV</a>

    @if($canManage)
      <form method="POST" action="{{ route('school.houses.parents-bulk-assign', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="margin-top:1rem;display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div class="field" style="margin-bottom:0;flex:1;min-width:12rem">
          <label for="parent_file">Filled in CSV</label>
          <input id="parent_file" name="file" type="file" accept=".csv,text/csv" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </form>
    @endif
  </div>
</div>
@endsection
