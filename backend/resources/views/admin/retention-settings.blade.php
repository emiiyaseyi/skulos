@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Retention'])
@section('title', 'Retention')
@section('heading', 'Retention')
@section('crumb', 'How long notifications, communication logs, and login sessions are kept before automatic cleanup')
@section('content')
<div class="card" style="max-width:32rem">
  <p class="muted" style="margin-bottom:1.2rem">This is the platform wide default. A school can set its own notification and communication log windows under its Settings, which take over for that school only. Login session cleanup always uses this platform setting. A weekly job purges anything past its window.</p>
  <form method="POST" action="{{ route('admin.settings.retention.update') }}">
    @csrf
    <div class="field">
      <label for="notifications_days">Read notifications kept for (days)</label>
      <input id="notifications_days" name="notifications_days" type="number" min="7" max="3650" value="{{ $settings['notifications_days'] ?? 90 }}" required />
      <div class="hint">Unread notifications are never purged, regardless of age.</div>
    </div>
    <div class="field">
      <label for="communication_logs_days">Communication logs kept for (days)</label>
      <input id="communication_logs_days" name="communication_logs_days" type="number" min="7" max="3650" value="{{ $settings['communication_logs_days'] ?? 180 }}" required />
    </div>
    <div class="field">
      <label for="sessions_days">Expired login sessions kept for (days)</label>
      <input id="sessions_days" name="sessions_days" type="number" min="1" max="365" value="{{ $settings['sessions_days'] ?? 30 }}" required />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>

<div class="card" style="max-width:32rem;margin-top:1.1rem">
  <h3 style="margin-bottom:.3rem">Backup retention</h3>
  <p class="muted" style="margin-bottom:1.2rem">The platform wide default for how many backups (and how recent) are kept before old ones are automatically pruned, per school and for the platform's own backups alike. A school can set its own under its Settings. A backup is only deleted once it fails both rules below. Same weekly job as above.</p>
  <form method="POST" action="{{ route('admin.settings.retention.update-backups') }}">
    @csrf
    <div class="field">
      <label for="keep_count">Always keep the most recent (backups)</label>
      <input id="keep_count" name="keep_count" type="number" min="1" max="365" value="{{ $backupSettings['keep_count'] ?? 10 }}" required />
    </div>
    <div class="field">
      <label for="keep_days">Always keep backups newer than (days)</label>
      <input id="keep_days" name="keep_days" type="number" min="1" max="3650" value="{{ $backupSettings['keep_days'] ?? 90 }}" required />
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem">
      <input type="checkbox" name="prune_manual" value="1" @checked($backupSettings['prune_manual'] ?? false) /> Also prune backups triggered manually
      <span class="hint" style="margin:0">(off by default, so a deliberately-taken backup is never auto-deleted)</span>
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
