@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Staff'])
@section('title', 'Staff')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Staff</h1>
    <p class="subtitle">{{ number_format($total) }} on record.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    {{-- User report 2026-09-12: "Tunde Yusuf is both a Staff and a
         Parent, not seen here, also admin should have a section where
         he/she can see staff who are also parents somewhere from the
         staff portal." --}}
    <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.also-parents', ['school' => $school->slug]) }}">Also Parents{{ $alsoParentsCount ? " ({$alsoParentsCount})" : '' }}</a>
    @if($canManage)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.trashed', ['school' => $school->slug]) }}">Trashed</a>
    @endif
    @if($canCreate)
      <a class="btn btn-primary btn-sm" href="{{ route('school.staff.create', ['school' => $school->slug]) }}">Add staff</a>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.import', ['school' => $school->slug]) }}">Bulk import</a>
    @endif
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0;flex:1;min-width:14rem">
      <label for="staff_q">Search</label>
      <input id="staff_q" type="search" name="q" value="{{ $search }}" placeholder="Search by name, staff ID, or position" />
    </div>
    <div class="field" style="margin:0">
      <label for="staff_status">Status</label>
      <select id="staff_status" name="status" onchange="this.form.requestSubmit()">
        <option value="">All</option>
        @foreach($statusOptions as $option)
          <option value="{{ $option }}" @selected($status === $option)>{{ ucwords(str_replace('_', ' ', $option)) }}</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="staff_sort">Sort by</label>
      <select id="staff_sort" name="sort" onchange="this.form.requestSubmit()">
        <option value="name" @selected($sort === 'name')>Name</option>
        <option value="staff_id" @selected($sort === 'staff_id')>Staff ID</option>
        <option value="position" @selected($sort === 'position')>Position</option>
        <option value="status" @selected($sort === 'status')>Status</option>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="staff_dir">Direction</label>
      <select id="staff_dir" name="dir" onchange="this.form.requestSubmit()">
        <option value="asc" @selected($dir === 'asc')>A to Z</option>
        <option value="desc" @selected($dir === 'desc')>Z to A</option>
      </select>
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">Apply</button>
  </form>
</div>

<div class="card">
  @if($staff->isEmpty())
    <p class="muted">No staff match your search.</p>
  @else
    @if($canManage)
      <form id="bulkStaffForm" method="POST" action="{{ route('school.staff.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    @endif
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="staff-select-all" aria-label="Select all staff" /></th>@endif
        <th>Staff ID</th><th>Name</th><th>Position</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($staff as $member)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkStaffForm" class="staff-row-check" name="staff_ids[]" value="{{ $member->id }}" aria-label="Select {{ $member->fullName() }}" /></td>
            @endif
            <td class="muted" style="font-variant-numeric:tabular-nums" data-label="Staff ID">{{ $member->staff_id }}</td>
            <td data-label="Name"><strong>{{ $member->fullName() }}</strong></td>
            <td class="muted" data-label="Position">{{ $member->position }}</td>
            <td data-label="Status"><span class="badge {{ $member->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucwords(str_replace('_', ' ', $member->status)) }}</span></td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $member->id]) }}">View</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($staff->hasPages())
      <div style="margin-top:1rem">{{ $staff->links() }}</div>
    @endif

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of staff above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkStaffForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="active">Mark active</button>
          <button form="bulkStaffForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="on_leave">Mark on leave</button>
          <button form="bulkStaffForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="suspended">Suspend</button>
          <button form="bulkStaffForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="resigned">Mark resigned</button>
          <button form="bulkStaffForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Move the selected staff to Trashed? You can restore them from there afterwards.')">Delete selected</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('staff-select-all');
          var boxes = document.querySelectorAll('.staff-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkStaffForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one staff member first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>
@endsection
