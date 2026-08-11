@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Student Profile Change Requests'])
@section('title', 'Student Profile Change Requests')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Student Profile Change Requests</h1>
    <p class="subtitle">Every request still awaiting a decision, at either stage, plus a quick look at how the queue is trending.</p>
  </div>
</div>

<style>
  .pcr-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin-bottom:1rem}
  @media(max-width:1000px){.pcr-stats{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:560px){.pcr-stats{grid-template-columns:1fr}}
</style>
<div class="pcr-stats">
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $stats['pending_class_teacher'] }}</div>
    <div class="muted" style="font-size:.8rem">Awaiting class teacher</div>
  </div>
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $stats['pending_admin'] }}</div>
    <div class="muted" style="font-size:.8rem">Awaiting your review</div>
  </div>
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $stats['approved_total'] }}</div>
    <div class="muted" style="font-size:.8rem">Approved (all time)</div>
  </div>
  <div class="card" style="padding:.9rem;text-align:center">
    <div style="font-size:1.6rem;font-weight:800">{{ $stats['rejected_total'] }}</div>
    <div class="muted" style="font-size:.8rem">Rejected (all time)</div>
  </div>
</div>
<p class="muted" style="margin:-.4rem 0 1rem;font-size:.85rem">
  For a full history with who decided what and when, see
  <a href="{{ route('school.approvals.history', ['school' => $school->slug]) }}">Approvals &gt; History</a>.
</p>

<div class="card" style="margin-bottom:1rem">
  <h3 style="margin-top:0">Awaiting class teacher</h3>
  <p class="muted" style="font-size:.85rem">Not yet actioned by the assigned class teacher. Nudge them, or override and decide yourself.</p>
  @if($pendingClassTeacher->isEmpty())
    <p class="muted" style="margin-bottom:0">Nothing waiting on a class teacher right now.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        <th>Student</th><th>Requested By</th><th>Changes</th><th>Class Teacher</th><th>Submitted</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($pendingClassTeacher as $changeRequest)
          <tr>
            <td data-label="Student">{{ $changeRequest->student?->fullName() ?? 'Unknown' }}</td>
            <td data-label="Requested By">{{ $changeRequest->submittedBy?->name ?? 'Unknown' }}</td>
            <td data-label="Changes" class="muted">
              @foreach($changeRequest->requested as $field => $value)
                {{ \Illuminate\Support\Str::headline($field) }}: {{ $changeRequest->original[$field] ?? 'Not set' }} &rarr; {{ $value ?: 'Not set' }}<br>
              @endforeach
            </td>
            <td data-label="Class Teacher">{{ $changeRequest->classTeacherStaff?->fullName() ?? 'No class teacher assigned' }}</td>
            <td data-label="Submitted">{{ $changeRequest->created_at->diffForHumans() }}</td>
            <td data-label="Actions">
              <form method="POST" action="{{ route('school.students.profile-change.admin.nudge-class-teacher', ['school' => $school->slug, 'requestId' => $changeRequest->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Nudge</button>
              </form>
              <form method="POST" action="{{ route('school.students.profile-change.admin.override-approve', ['school' => $school->slug, 'requestId' => $changeRequest->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Approve this directly, skipping the class teacher?')">Override &amp; Approve</button>
              </form>
              <form method="POST" action="{{ route('school.students.profile-change.admin.override-reject', ['school' => $school->slug, 'requestId' => $changeRequest->id]) }}" style="display:inline" class="reject-form">
                @csrf
                <input type="hidden" name="notes" value="" />
                <button class="btn btn-ghost btn-sm override-reject-btn" type="button">Override &amp; Reject</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card">
  <h3 style="margin-top:0">Awaiting your review</h3>
  @if($requests->isEmpty())
    <p class="muted" style="margin-bottom:0">Nothing awaiting your review right now.</p>
  @else
    <form id="bulkApproveForm" method="POST" action="{{ route('school.students.profile-change.admin.bulk-approve', ['school' => $school->slug]) }}">@csrf</form>
    <form id="bulkRejectForm" method="POST" action="{{ route('school.students.profile-change.admin.bulk-reject', ['school' => $school->slug]) }}">@csrf<input type="hidden" name="notes" value="" /></form>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="select-all" aria-label="Select all requests" /></th>
        <th>Student</th><th>Requested By</th><th>Changes</th><th>Class Teacher</th><th>Submitted</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($requests as $changeRequest)
          <tr>
            <td data-label="Select"><input type="checkbox" class="row-check" value="{{ $changeRequest->id }}" aria-label="Select {{ $changeRequest->student?->fullName() ?? 'Unknown' }}" /></td>
            <td data-label="Student">{{ $changeRequest->student?->fullName() ?? 'Unknown' }}</td>
            <td data-label="Requested By">{{ $changeRequest->submittedBy?->name ?? 'Unknown' }}</td>
            <td data-label="Changes" class="muted">
              @foreach($changeRequest->requested as $field => $value)
                {{ \Illuminate\Support\Str::headline($field) }}: {{ $changeRequest->original[$field] ?? 'Not set' }} &rarr; {{ $value ?: 'Not set' }}<br>
              @endforeach
            </td>
            <td data-label="Class Teacher">{{ $changeRequest->classTeacherStaff?->fullName() ?? 'No class teacher assigned' }}</td>
            <td data-label="Submitted">{{ $changeRequest->created_at->diffForHumans() }}</td>
            <td data-label="Actions" style="white-space:nowrap">
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
    document.querySelectorAll('.reject-btn, .override-reject-btn').forEach(function(btn){
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
