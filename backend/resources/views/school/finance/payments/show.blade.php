@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Payment Detail'])
@section('title', 'Payment Detail')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Payment Detail</h1>
    <p class="subtitle">{{ $payment->student?->fullName() }} &middot; {{ $payment->feeItem?->name ?? 'General payment' }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.payments.index', ['school' => $school->slug]) }}">Back to payments</a>
</div>

<div class="grid cols-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.6rem">Details</h3>
    <p><strong>Amount:</strong> ₦{{ number_format((float) $payment->amount, 2) }}</p>
    <p><strong>Method:</strong> Bank transfer</p>
    <p><strong>Bank:</strong> {{ $payment->bank_name ?: 'Not provided' }}</p>
    <p><strong>Reference:</strong> {{ $payment->reference ?: 'Not provided' }}</p>
    <p><strong>Submitted:</strong> {{ $payment->created_at->format('d M Y, g:ia') }}</p>
    <p>
      <strong>Status:</strong>
      <span class="badge {{ $payment->status === 'verified' ? 'badge-ok' : ($payment->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($payment->status) }}</span>
    </p>
    @if($payment->status !== 'pending')
      <p class="muted" style="font-size:.85rem">{{ ucfirst($payment->status) }} on {{ $payment->verified_at?->format('d M Y, g:ia') }}</p>
      @if($payment->notes)
        <p class="muted" style="font-size:.85rem">Note: {{ $payment->notes }}</p>
      @endif
    @endif

    @if($canVerify && $payment->status === 'pending')
      <div style="display:flex;gap:.6rem;margin-top:1rem">
        <form method="POST" action="{{ route('school.finance.payments.verify', ['school' => $school->slug, 'paymentId' => $payment->id]) }}">
          @csrf
          <button class="btn btn-primary btn-sm" type="submit">Verify</button>
        </form>
        <form method="POST" action="{{ route('school.finance.payments.reject', ['school' => $school->slug, 'paymentId' => $payment->id]) }}" onsubmit="return confirm('Reject this payment?')">
          @csrf
          <button class="btn btn-ghost btn-sm" type="submit">Reject</button>
        </form>
      </div>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.6rem">Evidence</h3>
    @if($payment->evidence_path)
      @php $evidenceUrl = route('school.finance.payments.evidence', ['school' => $school->slug, 'paymentId' => $payment->id]); @endphp
      @if(str_ends_with($payment->evidence_path, '.pdf'))
        <a class="btn btn-ghost btn-sm" href="{{ $evidenceUrl }}" target="_blank" rel="noopener">Open PDF evidence</a>
      @else
        <a href="{{ $evidenceUrl }}" target="_blank" rel="noopener">
          <img src="{{ $evidenceUrl }}" alt="Payment evidence" style="max-width:100%;border-radius:10px;border:1px solid var(--line)" />
        </a>
      @endif
    @else
      <p class="muted">No evidence attached.</p>
    @endif
  </div>
</div>
@endsection
