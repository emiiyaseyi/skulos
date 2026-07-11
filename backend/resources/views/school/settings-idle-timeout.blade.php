@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Idle Timeout'])
@section('title', 'Idle Timeout')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Idle timeout</h1>
    <p class="subtitle">Optional. Leave off to use SKULOS's platform default; turn it on to set your own for this school only.</p>
  </div>
</div>
<div class="card" style="max-width:32rem">
  @php($hasOwn = ! empty($settings))
  <form method="POST" action="{{ route('school.settings.idle-timeout.update', ['school' => $school->slug]) }}" id="idleForm">
    @csrf
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="use_own" value="1" style="width:auto" id="useOwn" @checked($hasOwn) /> Set our own idle timeout for this school
    </label>
    <div id="ownFields" style="{{ $hasOwn ? '' : 'display:none' }}">
      <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
        <input type="checkbox" name="enabled" value="1" style="width:auto" @checked($settings['enabled'] ?? true) /> Enable idle sign out
      </label>
      <div class="field">
        <label for="idle_seconds">Sign out after (seconds of inactivity)</label>
        <input id="idle_seconds" name="idle_seconds" type="number" min="10" max="86400" value="{{ $settings['idle_seconds'] ?? 30 }}" />
      </div>
      <div class="field">
        <label for="warning_seconds">Warning countdown (seconds)</label>
        <input id="warning_seconds" name="warning_seconds" type="number" min="5" max="600" value="{{ $settings['warning_seconds'] ?? 15 }}" />
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
