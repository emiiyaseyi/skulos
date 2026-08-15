@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Audit Trail'])
@section('title', 'Audit Trail')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Audit Trail</h1>
    <p class="subtitle">Every sign in and every change made in your school, with who did it and when.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" class="grid cols-4" style="align-items:end;gap:.7rem">
    <div class="field">
      <label for="action">Action</label>
      <input id="action" name="action" value="{{ $filters['action'] }}" placeholder="e.g. login, updated" />
    </div>
    <div class="field">
      <label for="from">From</label>
      <input id="from" name="from" type="date" value="{{ $filters['from'] }}" />
    </div>
    <div class="field">
      <label for="to">To</label>
      <input id="to" name="to" type="date" value="{{ $filters['to'] }}" />
    </div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-primary btn-sm" type="submit">Filter</button>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.audit.index', ['school' => $school->slug]) }}">Clear</a>
    </div>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <table class="table-stack">
    <thead>
      <tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>IP address</th></tr>
    </thead>
    <tbody>
      @forelse($logs as $log)
        <tr>
          <td data-label="When">{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d M Y, H:i') }}</td>
          <td data-label="Who">{{ $userNames[$log->user_id] ?? ($log->user_id ? 'User #'.$log->user_id : 'System') }}</td>
          <td data-label="Action">{{ $log->action }}</td>
          <td data-label="Record">{{ class_basename($log->auditable_type ?? '') }}{{ $log->auditable_id ? ' #'.$log->auditable_id : '' }}</td>
          <td data-label="IP address">{{ $log->ip_address ?? '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">No audit entries match these filters.</td></tr>
      @endforelse
    </tbody>
  </table>
  {{ $logs->links() }}
</div>

<div class="card">
  <h3 style="margin-bottom:.4rem">Archived months</h3>
  <p class="muted" style="font-size:.85rem;margin-bottom:.8rem">
    Entries older than {{ $retentionDays }} days are archived to a downloadable CSV and removed from the live list above,
    so your school's storage stays manageable. Each archive is emailed to your school administrators when it is created.
  </p>
  @if($archives->isEmpty())
    <p class="muted">No archives yet. The first one is created at the start of next month.</p>
  @else
    <ul class="storage-groups" style="list-style:none;padding:0;display:flex;flex-direction:column;gap:.4rem">
      @foreach($archives as $archive)
        <li style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.45rem 0;border-bottom:1px solid var(--line)">
          <span>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $archive['period'])->format('F Y') }}</span>
          <span style="display:flex;align-items:center;gap:.7rem">
            <span class="muted">{{ \App\Services\Maintenance\StorageService::humanBytes($archive['bytes']) }}</span>
            <a class="btn btn-ghost btn-sm" href="{{ route('school.audit.download', ['school' => $school->slug, 'period' => $archive['period']]) }}">Download CSV</a>
          </span>
        </li>
      @endforeach
    </ul>
  @endif
  <p class="muted" style="font-size:.8rem;margin-top:.9rem;margin-bottom:0">
    <strong>Please note:</strong> your school is responsible for downloading, storing and tracking its own audit records
    for as long as it needs them, including for any regulatory or legal requirement that applies to your school.
    SKULOS provides this archive and download as a convenience and cannot be held liable for audit records a school has not retained itself.
  </p>
</div>
@endsection
