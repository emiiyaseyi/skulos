@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Idle Timeout'])
@section('title', 'Idle Timeout')
@section('heading', 'Idle Timeout')
@section('crumb', 'How long a signed in user can be inactive before being warned and signed out')
@section('content')
<div class="card" style="max-width:32rem">
  <p class="muted" style="margin-bottom:1.2rem">This is the platform wide default. A school can set its own under its Settings, which takes over for that school only.</p>
  <form method="POST" action="{{ route('admin.settings.idle-timeout.update') }}">
    @csrf
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="enabled" value="1" @checked($settings['enabled'] ?? true) /> Enable idle sign out
    </label>
    <div class="field">
      <label for="idle_seconds">Sign out after (seconds of inactivity)</label>
      <input id="idle_seconds" name="idle_seconds" type="number" min="10" max="86400" value="{{ $settings['idle_seconds'] ?? 30 }}" required />
    </div>
    <div class="field">
      <label for="warning_seconds">Warning countdown (seconds)</label>
      <input id="warning_seconds" name="warning_seconds" type="number" min="5" max="600" value="{{ $settings['warning_seconds'] ?? 15 }}" required />
      <div class="hint">A "Resume or log out" warning shows for this long before signing out.</div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
