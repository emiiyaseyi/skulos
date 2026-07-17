@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Payments'])
@section('title', 'Payments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Payments</h1>
    <p class="subtitle">{{ $isFamily ? 'Bank transfer payments you have submitted.' : 'Bank transfer payments submitted to this school.' }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if(! $isFamily)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.fee-items.index', ['school' => $school->slug]) }}">Fee records</a>
    @endif
    @if($canSubmit)
      <a class="btn btn-primary btn-sm" href="{{ route('school.finance.payments.create', ['school' => $school->slug]) }}">Submit payment</a>
    @endif
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:16rem">
    <div class="field" style="margin:0">
      <label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="" {{ ! $status ? 'selected' : '' }}>All</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="verified" {{ $status === 'verified' ? 'selected' : '' }}>Verified</option>
        <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
      </select>
    </div>
  </form>
</div>

<div class="card">
  @if($payments->isEmpty())
    <p class="muted">No payments{{ $status ? ' with this status' : '' }} yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Date</th><th>Student</th><th>For</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($payments as $payment)
          <tr>
            <td class="muted" style="white-space:nowrap">{{ $payment->created_at->format('d M Y') }}</td>
            <td>{{ $payment->student?->fullName() }}</td>
            <td class="muted">{{ $payment->feeItem?->name ?? 'General payment' }}</td>
            <td>₦{{ number_format((float) $payment->amount, 2) }}</td>
            <td>
              <span class="badge {{ $payment->status === 'verified' ? 'badge-ok' : ($payment->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($payment->status) }}</span>
            </td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('school.finance.payments.show', ['school' => $school->slug, 'paymentId' => $payment->id]) }}">View</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $payments->links() }}</div>
  @endif
</div>
@endsection
