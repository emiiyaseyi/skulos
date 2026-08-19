@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Sent Mail'])
@section('title', 'Sent Mail')
@section('heading', 'Sent Mail')
@section('crumb', 'Every email SKULOS has sent, and what happened to it')
@section('content')

<div style="margin-bottom:1.2rem">
  <a class="btn btn-ghost btn-sm" href="{{ route('admin.mail-report.index') }}">View the mail report (per school, per audience)</a>
</div>

<div class="grid cols-3" style="margin-bottom:1.2rem">
  <div class="stat"><b>{{ number_format($totals['all']) }}</b><span>Total sent</span></div>
  <div class="stat"><b>{{ number_format($totals['sent']) }}</b><span>Delivered</span></div>
  <div class="stat"><b>{{ number_format($totals['failed']) }}</b><span>Failed</span></div>
</div>

<div class="card" style="margin-bottom:1.2rem">
  <form method="GET" class="grid cols-3" style="gap:1rem;align-items:end">
    <div class="field" style="margin:0">
      <label for="q">Search</label>
      <input id="q" name="q" value="{{ $search }}" placeholder="Recipient or subject" />
    </div>
    <div class="field" style="margin:0">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Any status</option>
        <option value="sent" @selected($status === 'sent')>Delivered</option>
        <option value="failed" @selected($status === 'failed')>Failed</option>
        <option value="sending" @selected($status === 'sending')>In progress</option>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="school_id">School</label>
      <select id="school_id" name="school_id">
        <option value="">Every school</option>
        @foreach($schools as $s)
          <option value="{{ $s->id }}" @selected($schoolId === $s->id)>{{ $s->name }}</option>
        @endforeach
      </select>
    </div>
    <div><button class="btn btn-primary btn-sm" type="submit">Apply</button></div>
  </form>
</div>

<div class="card">
  @if($logs->isEmpty())
    <p class="muted">No mail matches these filters yet.</p>
  @else
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr><th>Sent</th><th>Recipient</th><th>Subject</th><th>Type</th><th>School</th><th>Status</th></tr>
        </thead>
        <tbody>
          @foreach($logs as $log)
            @php($effective = $log->effectiveStatus())
            <tr>
              <td data-label="Sent" class="muted">{{ ($log->sent_at ?? $log->created_at)?->format('d M Y H:i') }}</td>
              <td data-label="Recipient">{{ $log->recipient }}</td>
              <td data-label="Subject">{{ $log->subject ?: '—' }}</td>
              <td data-label="Type" class="muted">{{ $log->shortMailable() }}</td>
              <td data-label="School" class="muted">{{ $log->school?->name ?? 'Platform' }}</td>
              <td data-label="Status">
                @if($effective === 'sent')
                  <span class="badge badge-ok">Delivered</span>
                @elseif($effective === 'failed')
                  <span class="badge badge-bad" @if($log->error) title="{{ $log->error }}" @endif>Failed</span>
                @else
                  <span class="badge badge-warn">In progress</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div style="margin-top:1rem">{{ $logs->links() }}</div>
  @endif
  <p class="hint" style="margin-top:1rem">
    Message bodies are deliberately not stored here. This is a delivery record, and email bodies
    routinely contain credentials such as a new owner's temporary password.
  </p>
</div>
@endsection
