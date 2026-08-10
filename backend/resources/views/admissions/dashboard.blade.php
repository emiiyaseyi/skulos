@extends('layouts.app', ['portal' => 'admissions', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'My Application'])
@section('title', 'My Application')
@section('bare', true)

{{--
  School branding header (UX audit finding, 2026-08-10): this screen had
  no header, logo, or nav chrome at all — a bare "Welcome" heading
  floating on a plain background, unlike every other applicant-facing
  screen in this same flow (login, apply, the landing page), which
  already got the school's logo added on 2026-08-06. Likely a real
  applicant's first genuine interaction with the platform after
  applying, so it is the one screen that most needs to look like it
  belongs to the school, not the one place that doesn't. Same .adm-logo
  treatment those other screens already use, not a new pattern.
--}}
@push('head')
<style>
  .adm-logo{width:56px;height:56px;border-radius:14px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.4rem;overflow:hidden;flex:none}
  .adm-logo img{width:100%;height:100%;object-fit:contain}
</style>
@endpush

@section('content')
<div style="max-width:40rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.9rem">
      @php($logo = $school?->branding()['logo'] ?? null)
      <div class="adm-logo">
        @if($logo)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="{{ $school->name }} logo" />
        @else
          {{ strtoupper(substr($school->name ?? 'S', 0, 1)) }}
        @endif
      </div>
      <div>
        <h1 class="title">Welcome, {{ $applicant->name }}</h1>
        <p class="subtitle">Your application to {{ $school->name }} is in progress.</p>
      </div>
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
      <p class="muted" style="margin-bottom:.8rem">
        <a href="{{ route('school.admissions.letter', ['school' => $school->slug]) }}" target="_blank">View your admission letter</a>
      </p>

      @if(! $applicant->offer_response)
        <p class="muted" style="margin-bottom:1rem">Please accept or decline this offer to continue.</p>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.2rem">
          <form method="POST" action="{{ route('school.admissions.offer.accept', ['school' => $school->slug]) }}" style="flex:1">
            @csrf
            <button class="btn btn-primary btn-block" type="submit">Accept offer</button>
          </form>
          <form method="POST" action="{{ route('school.admissions.offer.decline', ['school' => $school->slug]) }}" style="flex:1" onsubmit="return confirm('Decline this offer of admission? This cannot be undone.')">
            @csrf
            <button class="btn btn-ghost btn-block" type="submit">Decline offer</button>
          </form>
        </div>
      @endif

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
    @php($convertedStudent = $applicant->convertedStudent)
    <div class="card" style="margin-bottom:1rem">
      @if($convertedStudent && $convertedStudent->status === \App\Models\Tenant\Student::STATUS_PENDING_ENROLLMENT)
        @if(! $convertedStudent->admission_fees_cleared_at)
          <h3 style="margin-bottom:.6rem">Offer accepted — pay school fees to continue</h3>
          <p class="muted" style="margin-bottom:.8rem">
            {{ $convertedStudent->fullName() }}'s admission number is {{ $convertedStudent->admission_number }}.
            Sign in to your parent portal to pay their school fees and complete enrollment.
          </p>
          <a class="btn btn-primary btn-sm" href="{{ $school->portalUrl('/login') }}">Go to parent portal</a>
        @else
          <h3 style="margin-bottom:.6rem">Fees cleared — awaiting class placement</h3>
          <p class="muted">{{ $convertedStudent->fullName() }}'s school fees have been received. Their class teacher will complete their enrollment shortly.</p>
        @endif
      @else
        <p class="muted">Your enrollment is complete! Check your email/phone for your new student portal sign in details.</p>
      @endif
    </div>
  @else
    @if(count($pipelineStages) > 1)
      @php($currentStage = (int) $applicant->pipeline_stage)
      <div class="card" style="margin-bottom:1rem">
        <h3 style="margin-bottom:.8rem">Where you are</h3>
        <div class="stack" style="gap:.55rem">
          @foreach($pipelineStages as $index => $stage)
            @php($stageDone = $index < $currentStage)
            @php($stageCurrent = $index === $currentStage)
            <div style="display:flex;align-items:center;gap:.6rem">
              <span style="
                flex:0 0 auto; width:1.4rem; height:1.4rem; border-radius:50%;
                display:flex; align-items:center; justify-content:center;
                font-size:.72rem; font-weight:800;
                @if($stageDone) background:var(--ok); color:#fff;
                @elseif($stageCurrent) background:var(--accent); color:var(--accent-ink);
                @else background:var(--bg); border:1px solid var(--line); color:var(--muted);
                @endif
              ">{{ $stageDone ? '✓' : $index + 1 }}</span>
              <span style="{{ $stageCurrent ? 'font-weight:700' : ($stageDone ? '' : 'color:var(--muted)') }}">{{ $stage }}</span>
            </div>
          @endforeach
        </div>
      </div>
    @endif

    <div class="card" style="margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Application form</h3>
      <p class="muted" style="margin-bottom:.8rem">Complete the school's application form with your details.</p>
      <a class="btn btn-primary btn-sm" href="{{ route('school.admissions.form', ['school' => $school->slug]) }}">Continue application form</a>
    </div>
    @if($assessmentCount > 0)
      <div class="card">
        <h3 style="margin-bottom:.6rem">Admission assessment</h3>
        <p class="muted" style="margin-bottom:.8rem">{{ $assessmentCount === 1 ? 'An assessment is' : $assessmentCount.' assessments are' }} ready for you to take, timed once you start.</p>
        <a class="btn btn-primary btn-sm" href="{{ route('school.admissions.assessment.available', ['school' => $school->slug]) }}">Go to assessment</a>
      </div>
    @endif
  @endif
</div>
@endsection
