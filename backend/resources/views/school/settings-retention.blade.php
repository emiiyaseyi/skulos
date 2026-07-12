@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Retention'])
@section('title', 'Retention')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Retention</h1>
    <p class="subtitle">Optional. Leave off to use SKULOS's platform default; turn it on to set your own notification and communication log windows for this school only.</p>
  </div>
</div>
<div class="card" style="max-width:32rem">
  @php($hasOwn = ! empty($settings))
  <form method="POST" action="{{ route('school.settings.retention.update', ['school' => $school->slug]) }}" id="retentionForm">
    @csrf
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="use_own" value="1" style="width:auto" id="useOwn" @checked($hasOwn) /> Set our own retention windows for this school
    </label>
    <div id="ownFields" style="{{ $hasOwn ? '' : 'display:none' }}">
      <div class="field">
        <label for="notifications_days">Read notifications kept for (days)</label>
        <input id="notifications_days" name="notifications_days" type="number" min="7" max="3650" value="{{ $settings['notifications_days'] ?? 90 }}" />
        <div class="hint">Unread notifications are never purged, regardless of age.</div>
      </div>
      <div class="field">
        <label for="communication_logs_days">Communication logs kept for (days)</label>
        <input id="communication_logs_days" name="communication_logs_days" type="number" min="7" max="3650" value="{{ $settings['communication_logs_days'] ?? 180 }}" />
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
  <script>
    document.getElementById('useOwn')?.addEventListener('change', function(){
      document.getElementById('ownFields').style.display = this.checked ? '' : 'none';
    });
  </script>
</div>
@endsection
