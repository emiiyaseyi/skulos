@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Send Broadcast'])
@section('title', 'Send Broadcast')
@section('heading', 'Send Broadcast')
@section('crumb', 'A message to a school\'s owner, admin, and leadership, by email')

@section('content')
<div class="card" style="max-width:38rem">
  <form method="POST" action="{{ route('admin.broadcast.send') }}">
    @csrf
    <div class="field">
      <label>Audience</label>
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
            <input type="checkbox" name="school_ids[]" value="{{ $school->id }}" style="width:auto" /> {{ $school->name }}
          </label>
        @endforeach
      </div>
    </div>

    <div class="field">
      <label for="subject">Subject</label>
      <input id="subject" name="subject" required maxlength="150" />
    </div>
    <div class="field">
      <label for="body">Message</label>
      <textarea id="body" name="body" rows="8" required maxlength="5000"></textarea>
    </div>
    <p class="hint" style="margin-bottom:1rem">Delivered to the owner, administrator, and leadership tier of every matched school, not every user at the school.</p>
    <button class="btn btn-primary btn-sm" type="submit">Send broadcast</button>
  </form>
</div>

<script>
function skulosToggleAudience() {
  var v = document.getElementById('audience').value;
  document.getElementById('planField').style.display = v === 'plan' ? '' : 'none';
  document.getElementById('schoolsField').style.display = v === 'selected' ? '' : 'none';
}
</script>
@endsection
