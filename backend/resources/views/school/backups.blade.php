@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Backups'])
@section('title', 'Backups')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Backups</h1>
    <p class="subtitle">Back up your school's data, download it, or restore from an earlier point if something goes wrong.</p>
  </div>
  @if($canManage)
    <form method="POST" action="{{ route('school.backups.store', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Back up now</button>
    </form>
  @endif
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.25rem;max-width:32rem">
  <h3 style="margin-bottom:.6rem">Automatic backups</h3>
  <p class="muted" style="margin-bottom:.8rem">Set a default schedule so this happens on its own.</p>
  <form method="POST" action="{{ route('school.backups.schedule', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label for="backup_frequency">Frequency</label>
        <select id="backup_frequency" name="backup_frequency">
          <option value="none" @selected($school->backup_frequency === 'none')>Off</option>
          <option value="daily" @selected($school->backup_frequency === 'daily')>Daily</option>
          <option value="weekly" @selected($school->backup_frequency === 'weekly')>Weekly (Monday)</option>
          <option value="monthly" @selected($school->backup_frequency === 'monthly')>Monthly (1st)</option>
        </select>
      </div>
      <div class="field">
        <label for="backup_time">Time of day</label>
        <input id="backup_time" name="backup_time" type="time" value="{{ $school->backup_time ? substr($school->backup_time,0,5) : '02:00' }}" />
      </div>
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">Save schedule</button>
  </form>
</div>
@endif

<div class="card">
  <h3 style="margin-bottom:.8rem">History</h3>
  @if($backups->isEmpty())
    <p class="muted">No backups yet. Use "Back up now" above to create the first one.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Taken</th><th>Type</th><th>Size</th><th></th></tr></thead>
      <tbody>
        @foreach($backups as $backup)
          <tr>
            <td>{{ $backup->created_at->toDayDateTimeString() }}@if($backup->restored_at)<div class="muted" style="font-size:.78rem">Restored {{ $backup->restored_at->diffForHumans() }}</div>@endif</td>
            <td class="muted">{{ ucfirst($backup->type) }}</td>
            <td class="muted">{{ $backup->humanSize() }}</td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.backups.download', ['school' => $school->slug, 'backupId' => $backup->id]) }}">Download</a>
              @if($canManage)
                <form method="POST" action="{{ route('school.backups.restore', ['school' => $school->slug, 'backupId' => $backup->id]) }}" style="display:inline" onsubmit="return confirm('Restore from this backup? Everything created after {{ $backup->created_at->toDayDateTimeString() }} will be lost.')">
                  @csrf
                  <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Restore</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
