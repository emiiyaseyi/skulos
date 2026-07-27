@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Approvals'])
@section('title', 'Approvals')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Approvals</h1>
    <p class="subtitle">Every profile change, password reset, student profile change, and report card review currently awaiting a decision, in one place.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.approvals.history', ['school' => $school->slug]) }}">History</a>
</div>

<div class="card">
  @if($items->isEmpty())
    <p class="muted" style="margin-bottom:0">Nothing is waiting on anyone right now.</p>
  @else
    <form id="bulkNudgeForm" method="POST" action="{{ route('school.approvals.bulk-nudge', ['school' => $school->slug]) }}">@csrf</form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="select-all" aria-label="Select all" /></th>
        <th>Type</th><th>Requested By</th><th>Pending With</th><th>What</th><th>Submitted</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($items as $item)
          <tr>
            <td><input type="checkbox" class="row-check" value="{{ $item['nudgeKey'] }}" aria-label="Select {{ $item['description'] }}" /></td>
            <td><span class="badge badge-warn">{{ $item['typeLabel'] }}</span></td>
            <td>{{ $item['requestedBy'] }}</td>
            <td class="muted">{{ $item['pendingWith'] }}</td>
            <td class="muted">{{ $item['description'] }}</td>
            <td class="muted">{{ $item['submittedAt']?->diffForHumans() }}</td>
            <td style="white-space:nowrap">
              @if($item['approveRoute'])
                <form method="POST" action="{{ $item['approveRoute'] }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                </form>
              @endif
              @if($item['rejectRoute'])
                <form method="POST" action="{{ $item['rejectRoute'] }}" style="display:inline" class="reject-form">
                  @csrf
                  <input type="hidden" name="notes" value="" />
                  <button class="btn btn-ghost btn-sm reject-btn" type="button">Reject</button>
                </form>
              @endif
              @if($item['manageRoute'] ?? null)
                <a class="btn btn-ghost btn-sm" href="{{ $item['manageRoute'] }}">Manage</a>
              @endif
              <form method="POST" action="{{ route('school.approvals.nudge', ['school' => $school->slug]) }}" style="display:inline">
                @csrf
                <input type="hidden" name="key" value="{{ $item['nudgeKey'] }}" />
                <button class="btn btn-ghost btn-sm" type="submit">Nudge</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number above, then nudge everyone selected at once.</span>
      <button type="button" class="btn btn-ghost btn-sm" id="bulk-nudge-btn">Nudge selected</button>
    </div>

    <div style="margin-top:1rem">{{ $items->links() }}</div>
  @endif
</div>

<script>
  (function(){
    document.querySelectorAll('.reject-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var notes = prompt('Why is this being rejected? A reason is required.');
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

    document.getElementById('bulk-nudge-btn')?.addEventListener('click', function(){
      var ids = Array.prototype.filter.call(boxes(), function(cb){ return cb.checked; }).map(function(cb){ return cb.value; });
      if (! ids.length) { alert('Tick at least one item first.'); return; }
      var form = document.getElementById('bulkNudgeForm');
      form.querySelectorAll('input[name="keys[]"]').forEach(function(el){ el.remove(); });
      ids.forEach(function(id){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'keys[]';
        input.value = id;
        form.appendChild(input);
      });
      form.submit();
    });
  })();
</script>
@endsection
