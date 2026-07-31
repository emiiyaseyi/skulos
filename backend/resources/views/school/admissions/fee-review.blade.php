@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admission Fee Review'])
@section('title', 'Admission Fee Review')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Admission Fee Review</h1>
    <p class="subtitle">Confirm or reject an applicant's uploaded fee evidence. Confirming an application fee issues sign in details; confirming an acceptance fee readies the applicant for enrollment.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if(session('error'))
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ session('error') }}</div>
@endif

<div class="card">
  @if($payments->isEmpty())
    <p class="muted">No application fee submissions yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Applicant</th><th>Fee</th><th>Reference</th><th>Amount</th><th>Evidence</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($payments as $payment)
          <tr>
            <td data-label="Applicant"><strong>{{ $payment->applicant?->name }}</strong><div class="muted">{{ $payment->applicant?->phone }}</div></td>
            <td class="muted" data-label="Fee">{{ $payment->fee_type === 'acceptance' ? 'Acceptance' : 'Application' }}</td>
            <td class="muted" data-label="Reference">{{ $payment->reference ?: 'Not given' }}</td>
            <td class="muted" data-label="Amount">&#8358;{{ number_format($payment->amount, 2) }}</td>
            <td data-label="Evidence">
              @if($payment->evidence_path)
                <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.fees.evidence', ['school' => $school->slug, 'paymentId' => $payment->id]) }}" target="_blank" rel="noopener">View</a>
              @else
                <span class="muted">None</span>
              @endif
            </td>
            <td data-label="Status">
              <span class="badge {{ $payment->status === 'verified' ? 'badge-ok' : ($payment->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($payment->status) }}</span>
            </td>
            <td style="white-space:nowrap">
              @if($payment->status === 'pending')
                <form method="POST" action="{{ route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Verify</button>
                </form>
                <details style="display:inline-block">
                  <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Reject</summary>
                  <form method="POST" action="{{ route('school.admissions.fees.reject', ['school' => $school->slug, 'paymentId' => $payment->id]) }}" style="margin-top:.5rem;min-width:14rem">
                    @csrf
                    <textarea name="notes" rows="2" placeholder="Reason (optional)"></textarea>
                    <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:.4rem">Confirm reject</button>
                  </form>
                </details>
              @else
                <span class="muted">{{ $payment->notes ?: 'Decided' }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $payments->links() }}</div>
  @endif
</div>
@endsection
