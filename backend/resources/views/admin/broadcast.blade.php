@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Send Broadcast'])
@section('title', 'Send Broadcast')
@section('heading', 'Send Broadcast')
@section('crumb', 'A message to a school\'s owner, admin, and leadership: emailed, and delivered as a sign-in pop-up they acknowledge')

@section('content')
<div class="card" style="max-width:38rem">
  <form method="POST" action="{{ route('admin.broadcast.send') }}" enctype="multipart/form-data">
    @csrf
    <div class="field">
      <label>Schools</label>
      <select name="audience" id="audience" onchange="skulosToggleAudience()">
        <option value="all">Every provisioned school</option>
        <option value="plan">Schools on a specific plan</option>
        <option value="selected">Selected schools</option>
      </select>
    </div>

    <div class="field" id="planField" style="display:none">
      <label for="plan_id">Plan</label>
      <select id="plan_id" name="plan_id">
        @foreach($plans as $plan)
          <option value="{{ $plan->id }}">{{ $plan->name }}</option>
        @endforeach
      </select>
    </div>

    <div class="field" id="schoolsField" style="display:none">
      <label>Schools</label>
      <div style="max-height:12rem;overflow-y:auto;border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem">
        @foreach($schools as $school)
          <label style="display:flex;align-items:center;gap:.5rem;font-weight:500;padding:.25rem 0">
            <input type="checkbox" name="school_ids[]" value="{{ $school->id }}" /> {{ $school->name }}
          </label>
        @endforeach
      </div>
    </div>

    <div class="field">
      <label for="recipient_group">Reach, within each matched school</label>
      <select id="recipient_group" name="recipient_group">
        @foreach($recipientGroups as $key => $label)
          <option value="{{ $key }}" @selected($key === 'owners_and_admins')>{{ $label }}</option>
        @endforeach
      </select>
    </div>

    <div class="field">
      <label for="subject">Subject</label>
      <input id="subject" name="subject" required maxlength="150" />
    </div>
    <div class="field">
      <label>Message</label>
      @include('school.widgets._richtext', ['name' => 'body', 'value' => old('body')])
    </div>
    <div class="field">
      <label for="image">Image (optional)</label>
      <input id="image" name="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif" />
      <p class="hint">A light photo works best. It is compressed automatically.</p>
    </div>
    <p class="hint" style="margin-bottom:1rem">Emailed immediately, and delivered as a sign-in pop-up the recipient acknowledges, tracked below.</p>
    <button class="btn btn-primary btn-sm" type="submit">Send broadcast</button>
  </form>
</div>

@if($recent->isNotEmpty())
<div class="card" style="max-width:38rem;margin-top:1.5rem">
  <h2 class="card-title" style="margin-bottom:1rem">Recent broadcasts</h2>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Sent</th><th>Title</th><th>Reach</th><th>Acknowledged</th></tr></thead>
    <tbody>
      @foreach($recent as $b)
        @php
          $stats = app(\App\Services\Communication\PlatformBroadcastService::class)->stats($b, $schools);
          $pct = $stats['delivered'] > 0 ? round($stats['read'] / $stats['delivered'] * 100) : 0;
        @endphp
        <tr>
          <td class="muted" style="white-space:nowrap">{{ $b->created_at->format('d M Y') }}</td>
          <td><strong>{{ $b->title }}</strong><div class="muted" style="font-size:.8rem">{{ $b->schools_reached }} school(s), {{ $b->recipients_reached }} recipient(s)</div></td>
          <td class="muted" style="white-space:nowrap">{{ config('skulos.platform_message_audiences')[$b->recipient_group] ?? $b->recipient_group }}</td>
          <td style="white-space:nowrap">
            @if($stats['delivered'] === 0)
              <span class="muted">No recipients</span>
            @else
              <span class="badge {{ $pct >= 66 ? 'badge-ok' : ($pct >= 33 ? 'badge-warn' : 'badge-bad') }}">{{ $stats['read'] }} of {{ $stats['delivered'] }}</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endif

<script>
function skulosToggleAudience() {
  var v = document.getElementById('audience').value;
  document.getElementById('planField').style.display = v === 'plan' ? '' : 'none';
  document.getElementById('schoolsField').style.display = v === 'selected' ? '' : 'none';
}
</script>
@endsection
