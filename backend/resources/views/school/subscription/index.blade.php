@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Subscription'])
@section('title', 'Subscription')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Subscription</h1>
    <p class="subtitle">Your school's SKULOS plan and next payment.</p>
  </div>
</div>

<div class="grid cols-3" style="gap:1rem;margin-bottom:1.25rem">
  <div class="card stat-card" style="padding:1rem"><b>{{ $school->plan?->name ?? 'No plan' }}</b><span>Current plan</span></div>
  <div class="card stat-card" style="padding:1rem"><b>{{ number_format($learners) }}</b><span>Active learners</span></div>
  <div class="card stat-card" style="padding:1rem"><b>{{ $amountDue !== null ? '₦'.number_format($amountDue, 2) : 'N/A' }}</b><span>Amount due</span></div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.3rem">Next payment</h3>
  @if(! $school->next_billing_date)
    <p class="muted">No due date has been set yet.</p>
  @else
    <p class="muted">Due {{ $school->next_billing_date->format('d M Y') }}
      @if($daysUntilDue !== null)
        ({{ $daysUntilDue > 0 ? "{$daysUntilDue} day(s) remaining" : ($daysUntilDue === 0 ? 'due today' : abs($daysUntilDue).' day(s) overdue') }})
      @endif
    </p>
  @endif
</div>

<div class="grid cols-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.3rem">Pay by bank transfer</h3>
    <p class="muted" style="font-size:.85rem;margin-bottom:.8rem">Transfer to the account below, then submit your evidence.</p>
    <p><strong>Bank:</strong> {{ $bank['bank_name'] }}</p>
    <p><strong>Account name:</strong> {{ $bank['account_name'] }}</p>
    <p><strong>Account number:</strong> {{ $bank['account_number'] }}</p>

    <form method="POST" action="{{ route('school.subscription.store', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="margin-top:1.2rem" id="subPaymentForm">
      @csrf
      <div class="field">
        <label for="amount">Amount paid (₦)</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount', $amountDue) }}" aria-invalid="@error('amount') true @else false @enderror" @error('amount') aria-describedby="amount-error" @enderror />
        @error('amount')<p class="err" id="amount-error">{{ $message }}</p>@enderror
      </div>
      <div class="grid cols-2" style="gap:1rem">
        <div class="field">
          <label for="bank_name">Your bank (optional)</label>
          <input id="bank_name" name="bank_name" maxlength="120" value="{{ old('bank_name') }}" />
          @error('bank_name')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="reference">Transaction reference (optional)</label>
          <input id="reference" name="reference" maxlength="120" value="{{ old('reference') }}" />
          @error('reference')<p class="err">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="field">
        <label for="evidence">Evidence of payment</label>
        <input id="evidence" name="evidence" type="file" accept="image/png,image/jpeg,image/webp,.pdf" required aria-invalid="@error('evidence') true @else false @enderror" @error('evidence') aria-describedby="evidence-error" @enderror />
        @error('evidence')<p class="err" id="evidence-error">{{ $message }}</p>@enderror
      </div>
      <button class="btn btn-primary btn-sm" type="submit" id="subPaymentSubmit">Submit for verification</button>
    </form>
    <script>
      {{-- A multi-MB evidence upload on a slow connection previously gave
           no feedback at all, so it looked frozen (audit finding M-13,
           2026-07-17). --}}
      document.getElementById('subPaymentForm')?.addEventListener('submit', function(){
        var btn = document.getElementById('subPaymentSubmit');
        if (!btn) return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = 'Uploading...';
      });
    </script>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.6rem">Payment history</h3>
    @if($payments->isEmpty())
      <p class="muted">No payments submitted yet.</p>
    @else
      <ul class="rp-list">
        @foreach($payments as $payment)
          <li>
            <span><strong>₦{{ number_format((float) $payment->amount, 2) }}</strong> <span class="muted">{{ $payment->created_at->format('d M Y') }}</span></span>
            <span class="badge {{ $payment->status === 'verified' ? 'badge-ok' : ($payment->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($payment->status) }}</span>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</div>
@endsection
