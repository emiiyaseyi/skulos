@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Trashed students'])
@section('title', 'Trashed students')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Trashed students</h1>
    <p class="subtitle">Removed students wait here until restored or permanently deleted.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">Back to Students</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or admission number" />
  </form>
</div>

<div class="card">
  @if($students->isEmpty())
    <p class="muted">Nothing in Trashed{{ $search ? ' matching your search' : '' }}.</p>
  @else
    @if($canManage)
      <form id="bulkTrashedStudentsForm" method="POST" action="{{ route('school.students.trashed.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="trashed-student-select-all" aria-label="Select all trashed students" /></th>@endif
        <th>Admission No.</th><th>Name</th><th>Removed</th>
      </tr></thead>
      <tbody>
        @foreach($students as $student)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkTrashedStudentsForm" class="trashed-student-row-check" name="student_ids[]" value="{{ $student->id }}" aria-label="Select {{ $student->fullName() }}" /></td>
            @endif
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
            <td><strong>{{ $student->fullName() }}</strong></td>
            <td class="muted">{{ $student->deleted_at->diffForHumans() }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($students->hasPages())
      <div style="margin-top:1rem">{{ $students->links() }}</div>
    @endif

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkTrashedStudentsForm" class="btn btn-primary btn-sm" type="submit" name="action" value="restore">Restore selected</button>
          <button form="bulkTrashedStudentsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="force-delete" onclick="return confirm('Permanently delete the selected students? This cannot be undone.')">Delete permanently</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('trashed-student-select-all');
          var boxes = document.querySelectorAll('.trashed-student-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkTrashedStudentsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one student first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>
@endsection
