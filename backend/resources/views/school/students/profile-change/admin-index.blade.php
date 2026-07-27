@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Student Profile Change Requests'])
@section('title', 'Student Profile Change Requests')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Student Profile Change Requests</h1>
    <p class="subtitle">Approved by the class teacher and awaiting your final decision before it applies to the student and parent's details.</p>
  </div>
</div>

<div class="card">
  @if($requests->isEmpty())
    <p class="muted" style="margin-bottom:0">Nothing awaiting your review right now.</p>
  @else
    <form id="bulkApproveForm" method="POST" action="{{ route('school.students.profile-change.admin.bulk-approve', ['school' => $school->slug]) }}">@csrf</form>
    <form id="bulkRejectForm" method="POST" action="{{ route('school.students.profile-change.admin.bulk-reject', ['school' => $school->slug]) }}">@csrf<input type="hidden" name="notes" value="" /></form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="select-all" aria-label="Select all requests" /></th>
        <th>Student</th><th>Requested By</th><th>Changes</th><th>Class Teacher</th><th>Submitted</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($requests as $changeRequest)
          <tr>
            <td><input type="checkbox" class="row-check" value="{{ $changeRequest->id }}" aria-label="Select {{ $changeRequest->student?->fullName() ?? 'Unknown' }}" /></td>
            <td>{{ $changeRequest->student?->fullName() ?? 'Unknown' }}</td>
            <td>{{ $changeRequest->submittedBy?->name ?? 'Unknown' }}</td>
            <td class="muted">
              @foreach($changeRequest->requested as $field => $value)
                {{ \Illuminate\Support\Str::headline($field) }}: {{ $changeRequest->original[$field] ?? 'Not set' }} &rarr; {{ $value ?: 'Not set' }}<br>
              @endforeach
            </td>
            <td>{{ $changeRequest->classTeacherStaff?->fullName() ?? 'No class teacher assigned' }}</td>
            <td>{{ $changeRequest->created_at->diffForHumans() }}</td>
            <td style="white-space:nowrap">
              <form method="POST" action="{{ route('school.students.profile-change.admin.approve', ['school' => $school->slug, 'requestId' => $changeRequest->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.students.profile-change.admin.reject', ['school' => $school->slug, 'requestId' => $changeRequest->id]) }}" style="display:inline" class="reject-form">
                @csrf
                <input type="hidden" name="notes" value="" />
                <button class="btn btn-ghost btn-sm reject-btn" type="button">Reject</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number above, then act on all of them at once.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary btn-sm" id="bulk-approve-btn">Approve selected</button>
        <button type="button" class="btn btn-ghost btn-sm" id="bulk-reject-btn">Reject selected</button>
      </div>
    </div>
  @endif
</div>

<script>
  (function(){
    document.querySelectorAll('.reject-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var notes = prompt('Why is this change request being rejected? A reason is required.');
        if (notes === null) return;
        if (! notes.trim()) { alert('A reason is required to reject a request.'); return; }
        var form = btn.closest('form');
        form.querySelector('[name="notes"]').value = notes.trim();
        form.submit();
      });
    });

    var all = document.getElementById('select-all');
    var boxes = function(){ return document.querySelectorAll('.row-check'); };
    all?.addEventListener('change', function(){ boxes().forEach(function(cb){ cb.checked = all.checked; }); });

    function selectedIds(){
      return Array.prototype.filter.call(boxes(), function(cb){ return cb.checked; }).map(function(cb){ return cb.value; });
    }

    function fillIds(form, ids){
      form.querySelectorAll('input[name="request_ids[]"]').forEach(function(el){ el.remove(); });
      ids.forEach(function(id){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'request_ids[]';
        input.value = id;
        form.appendChild(input);
      });
    }

    document.getElementById('bulk-approve-btn')?.addEventListener('click', function(){
      var ids = selectedIds();
      if (! ids.length) { alert('Tick at least one request first.'); return; }
      var form = document.getElementById('bulkApproveForm');
      fillIds(form, ids);
      form.submit();
    });

    document.getElementById('bulk-reject-btn')?.addEventListener('click', function(){
      var ids = selectedIds();
      if (! ids.length) { alert('Tick at least one request first.'); return; }
      var notes = prompt('Why are these being rejected? A reason is required.');
      if (notes === null) return;
      if (! notes.trim()) { alert('A reason is required to reject a request.'); return; }
      var form = document.getElementById('bulkRejectForm');
      fillIds(form, ids);
      form.querySelector('[name="notes"]').value = notes.trim();
      form.submit();
    });
  })();
</script>
@endsection
