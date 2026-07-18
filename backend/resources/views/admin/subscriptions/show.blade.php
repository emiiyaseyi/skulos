@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => $school->name])
@section('title', $school->name.' Subscription')
@section('heading', $school->name)
@section('crumb', 'Subscription detail and actions')
@section('content')

@php $locked = $school->status === 'suspended' && $school->suspension_reason === 'billing'; @endphp

@if($locked)
  <div class="flash flash-bad" style="margin-bottom:1.1rem">This school is locked out for an overdue subscription.</div>
@endif

<div class="grid cols-3" style="gap:1rem;margin-bottom:1.25rem">
  <div class="card stat" style="padding:1rem"><b>{{ number_format($learners) }}</b><span>Active learners</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ $amountDue !== null ? '₦'.number_format($amountDue, 2) : 'N/A' }}</b><span>Amount due</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ $school->next_billing_date?->format('d M Y') ?? 'Not set' }}</b><span>Due date</span></div>
</div>

<div class="grid cols-2" style="gap:1rem;align-items:start;margin-bottom:1.25rem">
  <div class="card">
    <h3 style="margin-bottom:.6rem">Actions</h3>
    <div style="display:flex;flex-direction:column;gap:.7rem">
      <form method="POST" action="{{ route('admin.subscriptions.remind', ['school' => $school->id]) }}">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit">Send reminder</button>
      </form>

      <form method="POST" action="{{ route('admin.subscriptions.extend', ['school' => $school->id]) }}" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div class="field" style="margin:0">
          <label for="days">Extend by (days)</label>
          <input id="days" name="days" type="number" min="1" max="365" value="14" required style="max-width:6rem" />
        </div>
        <button class="btn btn-ghost btn-sm" type="submit">Grant extension</button>
      </form>

      @if($locked)
        <form method="POST" action="{{ route('admin.subscriptions.reinstate', ['school' => $school->id]) }}" onsubmit="return confirm('Restore access for {{ $school->name }}?')">
          @csrf
          <button class="btn btn-primary btn-sm" type="submit">Restore access</button>
        </form>
      @else
        <form method="POST" action="{{ route('admin.subscriptions.demand', ['school' => $school->id]) }}" onsubmit="return confirm('This will lock {{ $school->name }} out of SKULOS entirely until you restore access. Continue?')">
          @csrf
          <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--bad)">Demand payment (lock out)</button>
        </form>
      @endif
    </div>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.6rem">Recent activity</h3>
    @if($events->isEmpty())
      <p class="muted">No reminders, extensions, or demands yet.</p>
    @else
      <ul class="rp-list">
        @foreach($events as $event)
          <li><span><strong>{{ ucfirst($event->type) }}</strong> <span class="muted">by {{ $event->actionedBy?->name ?? 'System' }}{{ $event->note ? '. '.$event->note : '' }}</span></span><span class="muted">{{ $event->created_at->format('d M Y') }}</span></li>
        @endforeach
      </ul>
    @endif
  </div>
</div>

<div class="card">
  <h3 style="margin-bottom:.6rem">Payments</h3>
  @if($payments->isEmpty())
    <p class="muted">No payments submitted yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Date</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($payments as $payment)
          <tr>
            <td class="muted">{{ $payment->created_at->format('d M Y') }}</td>
            <td>₦{{ number_format((float) $payment->amount, 2) }}</td>
            <td><span class="badge {{ $payment->status === 'verified' ? 'badge-ok' : ($payment->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($payment->status) }}</span></td>
            <td style="white-space:nowrap">
              @if($payment->evidence_path)
                <a class="btn btn-ghost btn-sm" href="{{ route('admin.subscriptions.payments.evidence', ['payment' => $payment->id]) }}" target="_blank" rel="noopener">Evidence</a>
              @endif
              @if($payment->status === 'pending')
                <form method="POST" action="{{ route('admin.subscriptions.payments.verify', ['payment' => $payment->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Verify</button>
                </form>
                <form method="POST" action="{{ route('admin.subscriptions.payments.reject', ['payment' => $payment->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Reject</button>
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
