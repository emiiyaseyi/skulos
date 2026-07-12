@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Staff'])
@section('title', 'Staff')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Staff</h1>
    <p class="subtitle">{{ number_format($total) }} on record.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canManage)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.trashed', ['school' => $school->slug]) }}">Trashed</a>
    @endif
    @if($canCreate)
      <a class="btn btn-primary btn-sm" href="{{ route('school.staff.create', ['school' => $school->slug]) }}">Add staff</a>
    @endif
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name, staff ID, or position" />
  </form>
</div>

<div class="card">
  @if($staff->isEmpty())
    <p class="muted">No staff match your search.</p>
  @else
    @if($canManage)
      <form id="bulkStaffForm" method="POST" action="{{ route('school.staff.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
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
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $member->staff_id }}</td>
            <td><strong>{{ $member->fullName() }}</strong></td>
            <td class="muted">{{ $member->position }}</td>
            <td><span class="badge {{ $member->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucwords(str_replace('_', ' ', $member->status)) }}</span></td>
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
