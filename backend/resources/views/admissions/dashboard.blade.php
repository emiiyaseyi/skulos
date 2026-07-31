@extends('layouts.app', ['portal' => 'admissions', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'My Application'])
@section('title', 'My Application')
@section('bare', true)

@section('content')
<div style="max-width:40rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">Welcome, {{ $applicant->name }}</h1>
      <p class="subtitle">Your application to {{ $school->name }} is in progress.</p>
    </div>
    <form method="POST" action="{{ route('school.admissions.logout', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
    </form>
  </div>

  @if(session('status'))
    <div class="flash flash-ok" style="margin-bottom:1rem">{{ session('status') }}</div>
  @endif

  <div class="card" style="margin-bottom:1rem">
    <p class="muted">Your application window is open until {{ $applicant->expires_at?->toFormattedDateString() ?? 'further notice' }}.</p>
  </div>

  @if($applicant->account_status === 'pending_fee')
    <div class="card" style="margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Application fee</h3>
      @if(! $applicationPayment)
        @if($bankAccounts->isNotEmpty())
          <div style="margin-bottom:1rem;text-align:center">
            <div class="muted" style="font-size:.85rem;margin-bottom:.5rem">Send your application fee to any one of these accounts, then upload your evidence below.</div>
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
        <p class="muted" style="margin-bottom:1rem">Upload proof of your application fee payment to continue.</p>
        <form method="POST" action="{{ route('school.admissions.application-fee', ['school' => $school->slug]) }}" enctype="multipart/form-data">
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
      @elseif($applicationPayment->status === 'pending')
        <p class="muted">Your payment evidence has been received and is awaiting confirmation from the school.</p>
      @elseif($applicationPayment->status === 'verified')
        <p class="muted">Your application fee has been confirmed. Check back here or refresh the page.</p>
      @else
        <p class="flash flash-bad">Your payment evidence was not accepted{{ $applicationPayment->notes ? ': '.$applicationPayment->notes : '.' }} Please submit new evidence below.</p>
        <form method="POST" action="{{ route('school.admissions.application-fee', ['school' => $school->slug]) }}" enctype="multipart/form-data">
          @csrf
          <div class="field">
            <label for="evidence2">Payment evidence</label>
            <input id="evidence2" name="evidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
          </div>
          <button class="btn btn-primary btn-block" type="submit">Resubmit evidence</button>
        </form>
      @endif
    </div>
    <div class="card" style="margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Application form</h3>
      <p class="muted" style="margin-bottom:.8rem">You don't have to wait for your fee to be confirmed to fill in the rest of your details.</p>
      <a class="btn btn-primary btn-sm" href="{{ route('school.admissions.form', ['school' => $school->slug]) }}">Continue application form</a>
    </div>
  @elseif($applicant->decision_status === 'offered')
    <div class="card" style="margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Congratulations, you have been offered admission!</h3>
      @if(! $acceptanceFeeRequired)
        <p class="muted">No acceptance fee is required. The school will complete your enrollment shortly.</p>
      @elseif(! $acceptancePayment)
        @if($bankAccounts->isNotEmpty())
          <div style="margin-bottom:1rem;text-align:center">
            <div class="muted" style="font-size:.85rem;margin-bottom:.5rem">Send your acceptance fee to any one of these accounts, then upload your evidence below.</div>
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
        <p class="muted" style="margin-bottom:1rem">Upload proof of your acceptance fee payment to secure your place.</p>
        <form method="POST" action="{{ route('school.admissions.acceptance-fee', ['school' => $school->slug]) }}" enctype="multipart/form-data">
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
          <button class="btn btn-primary btn-block" type="submit">Submit acceptance fee evidence</button>
        </form>
      @elseif($acceptancePayment->status === 'pending')
        <p class="muted">Your acceptance fee evidence is awaiting confirmation from the school.</p>
      @elseif($acceptancePayment->status === 'verified')
        <p class="muted">Your acceptance fee has been confirmed. The school will complete your enrollment shortly.</p>
      @else
        <p class="flash flash-bad">Your acceptance fee evidence was not accepted{{ $acceptancePayment->notes ? ': '.$acceptancePayment->notes : '.' }} Please submit new evidence below.</p>
        <form method="POST" action="{{ route('school.admissions.acceptance-fee', ['school' => $school->slug]) }}" enctype="multipart/form-data">
          @csrf
          <div class="field">
            <label for="evidence2">Payment evidence</label>
            <input id="evidence2" name="evidence" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
          </div>
          <button class="btn btn-primary btn-block" type="submit">Resubmit evidence</button>
        </form>
      @endif
    </div>
  @elseif($applicant->decision_status === 'waitlisted')
    <div class="card" style="margin-bottom:1rem">
      <p class="muted">You are currently on the waiting list for this class. The school will contact you if a place opens up.</p>
    </div>
  @elseif($applicant->decision_status === 'rejected')
    <div class="card" style="margin-bottom:1rem">
      <p class="muted">Your application was not successful this time.{{ $applicant->decision_notes ? ' '.$applicant->decision_notes : '' }}</p>
    </div>
  @elseif($applicant->decision_status === 'converted')
    <div class="card" style="margin-bottom:1rem">
      <p class="muted">Your enrollment is complete! Check your email/phone for your new student portal sign in details.</p>
    </div>
  @else
    <div class="card">
      <h3 style="margin-bottom:.6rem">Application form</h3>
      <p class="muted" style="margin-bottom:.8rem">Complete the school's application form with your details.</p>
      <a class="btn btn-primary btn-sm" href="{{ route('school.admissions.form', ['school' => $school->slug]) }}">Continue application form</a>
    </div>
  @endif
</div>
@endsection
