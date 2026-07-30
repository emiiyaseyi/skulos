@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Subscriptions'])
@section('title', 'Subscriptions')
@section('heading', 'Subscriptions')
@section('crumb', 'Every school\'s SKULOS subscription: due date, amount owed, and payment history')
@section('content')

<div class="page-head">
  <div></div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($pendingPayments > 0)
      <span class="badge badge-warn">{{ $pendingPayments }} payment(s) awaiting verification</span>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.settings.subscription-bank.edit') }}">Bank details</a>
  </div>
</div>

<div class="card">
  @if($rows->isEmpty())
    <p class="muted">No provisioned schools yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>School</th><th>Plan</th><th>Learners</th><th>Amount due</th><th>Due date</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($rows as $row)
          @php
            $school = $row['school'];
            $days = $row['daysUntilDue'];
            $overdue = $days !== null && $days < 0;
          @endphp
          <tr>
            <td data-label="School"><strong>{{ $school->name }}</strong></td>
            <td class="muted" data-label="Plan">{{ $school->plan?->name ?? 'No plan' }}</td>
            @if($row['unavailable'])
              <td colspan="2"><span class="badge badge-warn">Temporarily unavailable</span></td>
            @else
              <td data-label="Learners">{{ number_format($row['learners']) }}</td>
              <td data-label="Amount due">{{ $row['amountDue'] !== null ? '₦'.number_format($row['amountDue'], 2) : 'N/A' }}</td>
            @endif
            <td class="muted" style="white-space:nowrap" data-label="Due date">
              {{ $school->next_billing_date?->format('d M Y') ?? 'Not set' }}
              @if($days !== null)
                <span class="badge {{ $overdue ? 'badge-bad' : ($days <= 21 ? 'badge-warn' : 'badge-ok') }}">{{ $overdue ? abs($days).'d overdue' : "{$days}d left" }}</span>
              @endif
            </td>
            <td data-label="Status">
              @php
                $statusClass = match(true) {
                  $school->status === 'suspended' && $school->suspension_reason === 'billing' => 'badge-bad',
                  $school->status === 'suspended' => 'badge-bad',
                  $school->status === 'active' => 'badge-ok',
                  default => 'badge-warn',
                };
                $statusLabel = $school->status === 'suspended' && $school->suspension_reason === 'billing' ? 'Locked (billing)' : ucfirst($school->status);
              @endphp
              <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
            </td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('admin.subscriptions.show', ['school' => $school->id]) }}">Manage</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:.8rem">{{ $rows->links() }}</div>
  @endif
</div>
@endsection
