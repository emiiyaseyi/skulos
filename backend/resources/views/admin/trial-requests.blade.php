@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Trial Requests'])
@section('title', 'Trial Requests')
@section('heading', 'Trial Requests')
@section('crumb', 'Review Start a Trial leads from the marketing site')
@section('content')

<div class="stack" style="flex-direction:row;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
  @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
    <a class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('admin.trial-requests.index', ['status' => $key]) }}">
      {{ $label }}@if($key !== 'all') ({{ $counts[$key] }})@endif
    </a>
  @endforeach
</div>

@if($trials->isEmpty())
  <div class="card"><p class="muted">Nothing here.</p></div>
@else
  @php $anyPending = $trials->contains('status', 'pending'); @endphp

  @if($anyPending)
    <form id="bulkTrialsForm" method="POST" action="{{ route('admin.trial-requests.bulk-reject') }}">@csrf</form>
    <div class="card" style="margin-bottom:1.1rem">
      <div class="row-between" style="margin-bottom:.3rem;flex-wrap:wrap;gap:.6rem">
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem"><input type="checkbox" id="trial-select-all" /> Select all pending</label>
        <span class="muted">Bulk decline only, approving stays one school at a time.</span>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
        <div class="field" style="margin:0;flex:1;min-width:14rem">
          <label for="bulk_reject_note">Note to include (applies to every selected trial)</label>
          <textarea form="bulkTrialsForm" id="bulk_reject_note" name="review_note" rows="1" placeholder="Optional reason"></textarea>
        </div>
        <button form="bulkTrialsForm" class="btn btn-ghost btn-sm" type="submit" style="color:var(--bad)">Decline selected</button>
      </div>
    </div>
    <script>
      (function(){
        var all = document.getElementById('trial-select-all');
        var boxes = document.querySelectorAll('.trial-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkTrialsForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one pending trial first.'); }
        });
      })();
    </script>
  @endif

  @foreach($trials as $trial)
    <div class="card" style="margin-bottom:1.1rem">
      <div class="row-between" style="margin-bottom:.6rem">
        <div style="display:flex;align-items:center;gap:.6rem">
          @if($trial->status === 'pending')
            <input type="checkbox" form="bulkTrialsForm" class="trial-row-check" name="trial_ids[]" value="{{ $trial->id }}" aria-label="Select {{ $trial->school_name }}" />
          @endif
          <strong>{{ $trial->school_name }}</strong>
          <span class="badge {{ $trial->status === 'approved' ? 'badge-ok' : ($trial->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($trial->status) }}</span>
        </div>
        <span class="muted">{{ $trial->created_at->diffForHumans() }}</span>
      </div>
      <div style="overflow-x:auto">
      <table style="margin-bottom:.8rem">
        <tbody>
          <tr><th>Contact</th><td style="word-break:break-word">{{ $trial->contact_name }} &middot; {{ $trial->email }}@if($trial->phone) &middot; {{ $trial->phone }}@endif</td></tr>
          @if($trial->student_count)<tr><th>Students</th><td>{{ number_format($trial->student_count) }}</td></tr>@endif
          @if($trial->message)<tr><th>Message</th><td>{{ $trial->message }}</td></tr>@endif
          @if($trial->reviewed_at)<tr><th>Reviewed</th><td class="muted">{{ $trial->reviewed_at->toDayDateTimeString() }} by {{ $trial->reviewer?->name ?? 'Unknown' }}@if($trial->review_note) &middot; "{{ $trial->review_note }}"@endif</td></tr>@endif
        </tbody>
      </table>
      </div>

      @if($trial->status === 'pending')
        <div class="grid cols-2">
          <form method="POST" action="{{ route('admin.trial-requests.approve', $trial) }}">
            @csrf
            <div class="field">
              <label for="approve_note_{{ $trial->id }}">Note to include (setup status, demo, training)</label>
              <textarea id="approve_note_{{ $trial->id }}" name="review_note" rows="2" placeholder="We'll set up {{ $trial->school_name }} this week and schedule a demo and staff training."></textarea>
            </div>
            {{-- Put the school on a plan as it is set up (2026-08-16).
                 Without this a brand new school was created with no plan
                 at all, silently falling back to the default plan's
                 features and showing "No plan set" on Billing until
                 someone noticed. --}}
            <div class="field">
              <label for="approve_plan_{{ $trial->id }}">Plan</label>
              <select id="approve_plan_{{ $trial->id }}" name="plan_id">
                <option value="">Decide later</option>
                @foreach($plans as $plan)
                  <option value="{{ $plan->id }}">{{ $plan->name }} &mdash; {{ number_format($plan->price_per_student_per_term) }} per student per term</option>
                @endforeach
              </select>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Approve and email</button>
          </form>
          <form method="POST" action="{{ route('admin.trial-requests.reject', $trial) }}">
            @csrf
            <div class="field">
              <label for="reject_note_{{ $trial->id }}">Note to include</label>
              <textarea id="reject_note_{{ $trial->id }}" name="review_note" rows="2" placeholder="Optional reason"></textarea>
            </div>
            <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--bad)">Decline and email</button>
          </form>
        </div>
      @endif
    </div>
  @endforeach
  <div style="margin-top:1rem">{{ $trials->links() }}</div>
@endif
@endsection
