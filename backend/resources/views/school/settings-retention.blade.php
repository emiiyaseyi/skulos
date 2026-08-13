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
      <input type="checkbox" name="use_own" value="1" id="useOwn" @checked($hasOwn) /> Set our own retention windows for this school
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

<div class="card" style="max-width:32rem;margin-top:1.1rem">
  <h3 style="margin-bottom:.3rem">Backup retention</h3>
  <p class="muted" style="font-size:.85rem;margin-bottom:1rem">How many backups (and how recent) are kept before old ones are automatically pruned. A backup is only deleted once it fails both rules below.</p>
  @php($hasOwnBackup = ! empty($backupSettings))
  <form method="POST" action="{{ route('school.settings.retention.update-backups', ['school' => $school->slug]) }}">
    @csrf
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="use_own_backup" value="1" id="useOwnBackup" @checked($hasOwnBackup) /> Set our own backup retention for this school
    </label>
    <div id="ownBackupFields" style="{{ $hasOwnBackup ? '' : 'display:none' }}">
      <div class="field">
        <label for="keep_count">Always keep the most recent (backups)</label>
        <input id="keep_count" name="keep_count" type="number" min="1" max="365" value="{{ $backupSettings['keep_count'] ?? 10 }}" />
      </div>
      <div class="field">
        <label for="keep_days">Always keep backups newer than (days)</label>
        <input id="keep_days" name="keep_days" type="number" min="1" max="3650" value="{{ $backupSettings['keep_days'] ?? 90 }}" />
      </div>
      <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem">
        <input type="checkbox" name="prune_manual" value="1" @checked($backupSettings['prune_manual'] ?? false) /> Also prune backups I triggered manually
        <span class="hint" style="margin:0">(off by default, so a backup you deliberately took is never auto-deleted)</span>
      </label>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
  <script>
    document.getElementById('useOwnBackup')?.addEventListener('change', function(){
      document.getElementById('ownBackupFields').style.display = this.checked ? '' : 'none';
    });
  </script>
</div>
@endsection
