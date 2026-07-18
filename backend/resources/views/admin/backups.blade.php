@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Backups'])
@section('title', 'Backups')
@section('heading', 'Backups')
@section('crumb', 'Every school\'s last backup, at a glance')

@section('content')
<div class="card">
  <p class="muted" style="margin-bottom:1rem">Backing up, changing a schedule, downloading, and restoring a specific backup all still happen from that school's own detail page; this is the consolidated view so you can spot who is overdue without opening each one.</p>
  @if($schools->isEmpty())
    <p class="muted">No provisioned schools yet.</p>
  @else
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>School</th>
        <th>Schedule</th>
        <th>Last backup</th>
        <th>Type</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($schools as $school)
        @php($backup = $latestBackups->get($school->id))
        @php($overdueDays = $backup ? now()->diffInDays($backup->created_at) : null)
        @php($isOverdue = $school->backup_frequency !== 'none' && (! $backup || $overdueDays > match($school->backup_frequency) { 'daily' => 1, 'weekly' => 7, 'monthly' => 31, default => 9999 }))
        <tr>
          <td><a href="{{ route('admin.schools.show', ['school' => $school->id]) }}">{{ $school->name }}</a></td>
          <td class="muted" style="text-transform:capitalize">{{ $school->backup_frequency ?: 'none' }}{{ $school->backup_frequency !== 'none' && $school->backup_time ? ' at '.$school->backup_time : '' }}</td>
          <td class="muted">{{ $backup?->created_at?->format('d M Y, g:ia') ?? 'Never' }}</td>
          <td class="muted" style="text-transform:capitalize">{{ $backup?->type ?? 'N/A' }}</td>
          <td>
            @if($school->backup_frequency === 'none')
              <span class="badge">No schedule</span>
            @elseif($isOverdue)
              <span class="badge badge-warn">Overdue</span>
            @else
              <span class="badge badge-ok">Up to date</span>
            @endif
          </td>
          <td>
            <form method="POST" action="{{ route('admin.schools.backups.store', ['school' => $school->id]) }}">
              @csrf
              <button class="btn btn-ghost btn-sm" type="submit">Back up now</button>
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
