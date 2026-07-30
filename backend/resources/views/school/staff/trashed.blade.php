@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Trashed staff'])
@section('title', 'Trashed staff')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Trashed staff</h1>
    <p class="subtitle">Removed staff wait here until restored or permanently deleted.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.index', ['school' => $school->slug]) }}">Back to Staff</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or staff ID" />
  </form>
</div>

<div class="card">
  @if($staff->isEmpty())
    <p class="muted">Nothing in Trashed{{ $search ? ' matching your search' : '' }}.</p>
  @else
    @if($canManage)
      <form id="bulkTrashedStaffForm" method="POST" action="{{ route('school.staff.trashed.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="trashed-staff-select-all" aria-label="Select all trashed staff" /></th>@endif
        <th>Staff ID</th><th>Name</th><th>Position</th><th>Removed</th>
      </tr></thead>
      <tbody>
        @foreach($staff as $member)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkTrashedStaffForm" class="trashed-staff-row-check" name="staff_ids[]" value="{{ $member->id }}" aria-label="Select {{ $member->fullName() }}" /></td>
            @endif
            <td class="muted" style="font-variant-numeric:tabular-nums" data-label="Staff ID">{{ $member->staff_id }}</td>
            <td data-label="Name"><strong>{{ $member->fullName() }}</strong></td>
            <td class="muted" data-label="Position">{{ $member->position }}</td>
            <td class="muted" data-label="Removed">{{ $member->deleted_at->diffForHumans() }}</td>
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
        <span class="muted" style="font-size:.85rem">Tick any number above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkTrashedStaffForm" class="btn btn-primary btn-sm" type="submit" name="action" value="restore">Restore selected</button>
          <button form="bulkTrashedStaffForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="force-delete" onclick="return confirm('Permanently delete the selected staff? This cannot be undone.')">Delete permanently</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('trashed-staff-select-all');
          var boxes = document.querySelectorAll('.trashed-staff-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkTrashedStaffForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one staff member first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>
@endsection
