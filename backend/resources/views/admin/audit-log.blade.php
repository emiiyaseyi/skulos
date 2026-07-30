@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Audit Log'])
@section('title', 'Audit Log')
@section('heading', 'Audit Log')
@section('crumb', 'Every recorded platform level action, searchable and paginated')

@section('content')
<div class="card" style="margin-bottom:1.25rem">
  <div class="row-between" style="flex-wrap:wrap;gap:.8rem;align-items:flex-end">
    <form method="GET" action="{{ route('admin.audit-log.index') }}" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
      <div class="field" style="margin:0">
        <label for="action">Action contains</label>
        <input id="action" name="action" value="{{ $action }}" placeholder="e.g. school, broadcast, backup" />
      </div>
      <div class="field" style="margin:0">
        <label for="user_id">By</label>
        <select id="user_id" name="user_id">
          <option value="">Anyone</option>
          @foreach($users as $u)
            <option value="{{ $u->id }}" @selected($userId == $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field" style="margin:0">
        <label for="from">From</label>
        <input id="from" name="from" type="date" value="{{ $from }}" />
      </div>
      <div class="field" style="margin:0">
        <label for="to">To</label>
        <input id="to" name="to" type="date" value="{{ $to }}" />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Filter</button>
      @if($action !== '' || $userId || $from || $to)
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.audit-log.index') }}">Clear</a>
      @endif
    </form>
    <form method="POST" action="{{ route('admin.audit-log.verify') }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit" title="Recomputes every platform audit log row's hash and confirms none were altered outside the app">Verify integrity</button>
    </form>
  </div>
</div>

<div class="card">
  @if($logs->isEmpty())
    <p class="muted">No matching activity.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th><th>IP</th></tr></thead>
      <tbody>
        @foreach($logs as $log)
          <tr>
            <td class="muted" style="white-space:nowrap" data-label="When">{{ $log->created_at?->format('d M Y, H:i') }}</td>
            <td data-label="Who">{{ $log->user?->name ?? 'System' }}</td>
            <td data-label="Action">{{ str_replace('.', ' ', $log->action) }}</td>
            <td class="muted" data-label="Subject">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '' }}</td>
            <td class="muted" data-label="IP">{{ $log->ip_address }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $logs->links() }}</div>
  @endif
</div>
@endsection
