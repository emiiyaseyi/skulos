@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Platform Backups'])
@section('title', 'Platform Backups')
@section('heading', 'Platform Backups')
@section('crumb', 'Backups of the shared platform database itself, not any one school')

@section('content')
<div class="card" style="margin-bottom:1.25rem">
  <div class="row-between" style="flex-wrap:wrap;gap:.8rem">
    <p class="muted" style="max-width:34rem">This backs up the one database every school depends on (schools, users, plans, billing, licenses), distinct from a school's own tenant database backups. Runs automatically once a day; you can also trigger one by hand any time.</p>
    <form method="POST" action="{{ route('admin.platform-backups.store') }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Back up now</button>
    </form>
  </div>
</div>

<div class="card">
  @if($backups->isEmpty())
    <p class="muted">No backups yet. Use "Back up now" above to create the first one.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Taken</th><th>Type</th><th>Size</th><th>By</th><th></th></tr></thead>
      <tbody>
        @foreach($backups as $backup)
          <tr>
            <td data-label="Taken">{{ $backup->created_at->toDayDateTimeString() }}</td>
            <td class="muted" style="text-transform:capitalize" data-label="Type">{{ $backup->type }}</td>
            <td class="muted" data-label="Size">{{ $backup->humanSize() }}</td>
            <td class="muted" data-label="By">{{ $backup->initiator?->name ?? 'System (scheduled)' }}</td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('admin.platform-backups.download', ['backupId' => $backup->id]) }}">Download</a>
              <form method="POST" action="{{ route('admin.platform-backups.destroy', ['backupId' => $backup->id]) }}" style="display:inline" onsubmit="return confirm('Remove this backup? This only deletes the file, it does not affect the live platform database.')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
