@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Extension Requests'])
@section('title', 'Extension Requests')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Extension Requests</h1>
    <p class="subtitle">Teachers asking for more time to enter scores past a closed window.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.structure', ['school' => $school->slug]) }}">Back to Assessment Structure</a>
</div>

<div class="card">
  @if($requests->isEmpty())
    <p class="muted">No extension requests yet.</p>
  @else
    <form id="bulkExtensionsForm" method="POST" action="{{ route('school.examinations.window-extensions.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="ext-select-all" aria-label="Select all pending requests" /></th>
        <th>Teacher</th><th>Component</th><th>{{ \App\Support\TermLabel::current() }}</th><th>Reason</th><th>Requested close</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($requests as $req)
          <tr>
            <td>
              @if($req->status === 'pending')
                <input type="checkbox" form="bulkExtensionsForm" class="ext-row-check" name="extension_ids[]" value="{{ $req->id }}" aria-label="Select request from {{ $req->staff?->fullName() }}" />
              @endif
            </td>
            <td data-label="Teacher"><strong>{{ $req->staff?->fullName() }}</strong></td>
            <td class="muted" data-label="Component">{{ $req->window?->component?->name }}</td>
            <td class="muted" data-label="{{ \App\Support\TermLabel::current() }}">{{ $req->window?->term?->name }} ({{ $req->window?->term?->session?->name }})</td>
            <td class="muted" style="max-width:16rem" title="{{ $req->reason }}" data-label="Reason">{{ \Illuminate\Support\Str::limit($req->reason, 60) }}</td>
            <td class="muted" style="white-space:nowrap" data-label="Requested close">{{ $req->requested_close_at?->format('d M Y, g:ia') }}</td>
            <td data-label="Status">
              <span class="badge {{ $req->status === 'approved' ? 'badge-ok' : ($req->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($req->status) }}</span>
            </td>
            <td style="white-space:nowrap">
              @if($req->status === 'pending')
                <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('adjust-{{ $req->id }}').classList.toggle('show')">Review</button>
              @endif
            </td>
          </tr>
          @if($req->status === 'pending')
            <tr id="adjust-{{ $req->id }}" class="ext-review-row">
              <td colspan="8">
                <div style="display:flex;gap:1rem;flex-wrap:wrap;padding:.6rem 0">
                  <form method="POST" action="{{ route('school.examinations.window-extensions.approve', ['school' => $school->slug, 'extensionId' => $req->id]) }}" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
                    @csrf
                    <div class="field" style="margin:0">
                      <label style="font-size:.72rem">Close time (adjust if needed)</label>
                      <input type="datetime-local" name="requested_close_at" value="{{ $req->requested_close_at?->format('Y-m-d\TH:i') }}" />
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                  </form>
                  <form method="POST" action="{{ route('school.examinations.window-extensions.reject', ['school' => $school->slug, 'extensionId' => $req->id]) }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Reject</button>
                  </form>
                </div>
              </td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of pending requests above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkExtensionsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="approve">Approve selected</button>
        <button form="bulkExtensionsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="reject">Reject selected</button>
      </div>
    </div>
    <script>
      (function(){
        var all = document.getElementById('ext-select-all');
        var boxes = document.querySelectorAll('.ext-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkExtensionsForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one request first.'); }
        });
      })();
    </script>
  @endif
</div>

<style>
  .ext-review-row{display:none}
  .ext-review-row.show{display:table-row}
</style>
@endsection
