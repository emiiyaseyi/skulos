@extends('layouts.app', ['accent' => $accent])
@section('title', 'Application Status — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@section('content')
<div style="max-width:30rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="card" style="text-align:center;margin-bottom:1.25rem">
    <h1 class="title" style="margin-bottom:.6rem">Application Received</h1>
    <p class="subtitle" style="margin-bottom:1.2rem">Thank you, {{ $applicant->name }}. Your application reference is:</p>
    <p style="font-size:1.6rem;font-weight:800;letter-spacing:.02em;margin-bottom:1.2rem">{{ $applicant->admission_number }}</p>
    <p class="muted">Bookmark this page, or keep the link from your confirmation, to check your status and finish payment. You'll need this reference (or the phone number you gave) to sign back in once your account is activated.</p>
  </div>

  @if(session('status'))
    <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
  @endif

  @if($bankAccounts->isNotEmpty() && ! $feePayment)
    <div class="card" style="margin-bottom:1.25rem;text-align:center">
      <h3 style="margin-bottom:.3rem">Bank transfer details</h3>
      <p class="muted" style="margin-bottom:.8rem">Send your application fee to any one of these accounts, then upload your evidence below.</p>
      <div class="stack" style="gap:.7rem">
        @foreach($bankAccounts as $account)
          <div style="border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;text-align:center">
            <div style="font-weight:800">{{ $account->bank_name }}</div>
            <div class="muted" style="font-size:.85rem">{{ $account->account_name }}</div>
            <div style="font-variant-numeric:tabular-nums;font-weight:700;margin-top:.2rem">{{ $account->account_number }}</div>
          </div>
        @endforeach
      </div>
    </div>
  @endif

  <div class="card">
    <h3 style="margin-bottom:.6rem">Application fee</h3>
    @if(! $feePayment)
      <p class="muted" style="margin-bottom:1rem">Upload proof of your application fee payment (a bank transfer receipt or screenshot) to continue.</p>
      <form method="POST" action="{{ url()->full() }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
          <label for="bank_name">Bank name (optional)</label>
          <input id="bank_name" name="bank_name" type="text" />
        </div>
        <div class="field">
          <label for="reference">Transaction reference (optional)</label>
          <input id="reference" name="reference" type="text" />
        </div>
        <div class="field">
          <label for="evidence">Payment evidence</label>
          <input id="evidence" name="evidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Submit payment evidence</button>
      </form>
    @elseif($feePayment->status === 'pending')
      <p class="muted">Your payment evidence has been received and is awaiting confirmation from the school.</p>
    @elseif($feePayment->status === 'verified')
      <p class="muted" style="margin-bottom:1rem">Your application fee has been confirmed. Use these details to sign back in and continue your application any time.</p>
      @if($applicant->recoverablePassword())
        <div style="border:1px solid var(--line);border-radius:10px;padding:.8rem 1rem">
          <div class="muted" style="font-size:.8rem">Admission number</div>
          <div style="font-weight:800;margin-bottom:.5rem">{{ $applicant->admission_number }}</div>
          <div class="muted" style="font-size:.8rem">Password</div>
          <div style="font-weight:800;font-variant-numeric:tabular-nums">{{ $applicant->recoverablePassword() }}</div>
        </div>
      @endif
    @else
      <p class="flash flash-bad">Your payment evidence was not accepted{{ $feePayment->notes ? ': '.$feePayment->notes : '.' }} Please submit new evidence below.</p>
      <form method="POST" action="{{ url()->full() }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
          <label for="bank_name">Bank name (optional)</label>
          <input id="bank_name" name="bank_name" type="text" />
        </div>
        <div class="field">
          <label for="reference">Transaction reference (optional)</label>
          <input id="reference" name="reference" type="text" />
        </div>
        <div class="field">
          <label for="evidence">Payment evidence</label>
          <input id="evidence" name="evidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Resubmit payment evidence</button>
      </form>
    @endif
  </div>
</div>
@endsection
