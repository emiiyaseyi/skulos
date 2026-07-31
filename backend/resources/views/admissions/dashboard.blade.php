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

  @if($applicant->decision_status === 'offered')
    <div class="card" style="margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Congratulations, you have been offered admission!</h3>
      @if(! $acceptancePayment)
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
