@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Storage'])
@section('title', 'Storage')
@section('heading', 'Storage')
@section('crumb', 'What every school is actually using, largest first')

@section('content')
<div class="card" style="margin-bottom:1.1rem;text-align:center;max-width:22rem">
  <p class="muted" style="margin-bottom:.2rem">Total across all schools</p>
  <div style="font-size:1.6rem;font-weight:800">{{ \App\Services\Maintenance\StorageService::humanBytes($grandTotal) }}</div>
  <p class="muted" style="font-size:.82rem;margin-bottom:0">Databases, uploaded files, and kept backups combined</p>
</div>

<div class="card">
  <p class="muted" style="margin-bottom:1rem">Real measured bytes per school, never an estimate. Each school's own Health &amp; Storage page (under its portal's Settings) shows the same numbers broken down by category, with backup and cleanup actions.</p>
  @if($rows->isEmpty())
    <p class="muted">No provisioned schools yet.</p>
  @else
  <div style="overflow-x:auto">
  <table class="table-stack">
    <thead>
      <tr>
        <th>School</th>
        <th>Database</th>
        <th>Uploaded files</th>
        <th>Backups</th>
        <th>Total</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($rows as $row)
        <tr>
          <td data-label="School"><strong>{{ $row['school']->name }}</strong></td>
          <td data-label="Database">{{ \App\Services\Maintenance\StorageService::humanBytes($row['database_bytes']) }}</td>
          <td data-label="Uploaded files">{{ \App\Services\Maintenance\StorageService::humanBytes($row['files_bytes']) }}</td>
          <td data-label="Backups">{{ \App\Services\Maintenance\StorageService::humanBytes($row['backups_bytes']) }}</td>
          <td data-label="Total"><strong>{{ \App\Services\Maintenance\StorageService::humanBytes($row['total_bytes']) }}</strong></td>
          <td data-label="Actions"><a class="btn btn-ghost btn-sm" href="{{ route('admin.schools.show', $row['school']) }}">Manage</a></td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
  @endif
</div>
@endsection
