@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Profile approvals'])
@section('title', 'Profile approvals')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Profile approvals</h1>
    <p class="subtitle">Requested name, email, or phone changes waiting on your approval.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.profile.edit', ['school' => $school->slug]) }}">My profile</a>
</div>

<div class="card">
  @if($requests->isEmpty())
    <p class="muted">Nothing waiting on you right now.</p>
  @else
    <form id="bulkChangeApproveForm" method="POST" action="{{ route('school.profile.bulk-approve', ['school' => $school->slug]) }}">@csrf</form>
    <form id="bulkChangeRejectForm" method="POST" action="{{ route('school.profile.bulk-reject', ['school' => $school->slug]) }}">@csrf<input type="hidden" name="notes" value="" /></form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="change-select-all" aria-label="Select all requests" /></th>
        <th>Requested by</th><th>Change</th><th>Requested</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($requests as $req)
          <tr>
            <td><input type="checkbox" class="change-row-check" value="{{ $req->id }}" aria-label="Select {{ $req->applicant?->name ?? 'Unknown' }}" /></td>
            <td><strong>{{ $req->applicant?->name ?? 'Unknown' }}</strong></td>
            <td class="muted">
              @foreach($req->requested as $field => $value)
                {{ ucfirst($field) }}: {{ $req->original[$field] ?? '' }} &rarr; {{ $value }}<br>
              @endforeach
            </td>
            <td class="muted">{{ $req->created_at->diffForHumans() }}</td>
            <td style="white-space:nowrap">
              <form method="POST" action="{{ route('school.profile.approve', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.profile.reject', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline" class="reject-form">
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
        <button type="button" class="btn btn-primary btn-sm" id="change-bulk-approve-btn">Approve selected</button>
        <button type="button" class="btn btn-ghost btn-sm" id="change-bulk-reject-btn">Reject selected</button>
      </div>
    </div>
  @endif
</div>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:.6rem">Forgot-password requests</h3>
  @if($passwordRequests->isEmpty())
    <p class="muted">Nothing waiting on you right now.</p>
  @else
    <form id="bulkResetApproveForm" method="POST" action="{{ route('school.profile.reset.bulk-approve', ['school' => $school->slug]) }}">@csrf</form>
    <form id="bulkResetRejectForm" method="POST" action="{{ route('school.profile.reset.bulk-reject', ['school' => $school->slug]) }}">@csrf<input type="hidden" name="notes" value="" /></form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="reset-select-all" aria-label="Select all requests" /></th>
        <th>Requested by</th><th>Requested</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($passwordRequests as $req)
          <tr>
            <td><input type="checkbox" class="reset-row-check" value="{{ $req->id }}" aria-label="Select {{ $req->applicant?->name ?? 'Unknown' }}" /></td>
            <td><strong>{{ $req->applicant?->name ?? 'Unknown' }}</strong></td>
            <td class="muted">{{ $req->created_at->diffForHumans() }}</td>
            <td style="white-space:nowrap">
              <form method="POST" action="{{ route('school.profile.reset.approve', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.profile.reset.reject', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline" class="reject-form">
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
        <button type="button" class="btn btn-primary btn-sm" id="reset-bulk-approve-btn">Approve selected</button>
        <button type="button" class="btn btn-ghost btn-sm" id="reset-bulk-reject-btn">Reject selected</button>
      </div>
    </div>
  @endif
</div>

<script>
  (function(){
    document.querySelectorAll('.reject-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var notes = prompt('Why is this being rejected? A reason is required.');
        if (notes === null) return;
        if (! notes.trim()) { alert('A reason is required to reject a change.'); return; }
        var form = btn.closest('form');
        form.querySelector('[name="notes"]').value = notes.trim();
        form.submit();
      });
    });

    // A checkbox can only ever belong to one <form>, but here the same
    // selection has to feed either an "approve selected" or a "reject
    // selected" bulk action, so the row checkboxes stay unattached and
    // this wires each bulk button to gather the current selection into
    // hidden inputs on the right form at click time instead.
    function wireBulk(rowSelector, selectAllId, approveBtnId, rejectBtnId, approveFormId, rejectFormId, noun){
      var all = document.getElementById(selectAllId);
      var boxes = function(){ return document.querySelectorAll(rowSelector); };
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

      document.getElementById(approveBtnId)?.addEventListener('click', function(){
        var ids = selectedIds();
        if (! ids.length) { alert('Tick at least one '+noun+' first.'); return; }
        var form = document.getElementById(approveFormId);
        fillIds(form, ids);
        form.submit();
      });

      document.getElementById(rejectBtnId)?.addEventListener('click', function(){
        var ids = selectedIds();
        if (! ids.length) { alert('Tick at least one '+noun+' first.'); return; }
        var notes = prompt('Why are these being rejected? A reason is required.');
        if (notes === null) return;
        if (! notes.trim()) { alert('A reason is required to reject a change.'); return; }
        var form = document.getElementById(rejectFormId);
        fillIds(form, ids);
        form.querySelector('[name="notes"]').value = notes.trim();
        form.submit();
      });
    }

    wireBulk('.change-row-check', 'change-select-all', 'change-bulk-approve-btn', 'change-bulk-reject-btn', 'bulkChangeApproveForm', 'bulkChangeRejectForm', 'request');
    wireBulk('.reset-row-check', 'reset-select-all', 'reset-bulk-approve-btn', 'reset-bulk-reject-btn', 'bulkResetApproveForm', 'bulkResetRejectForm', 'request');
  })();
</script>
@endsection
