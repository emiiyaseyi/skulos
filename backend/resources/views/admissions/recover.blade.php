@extends('layouts.app', ['accent' => $accent])
@section('title', 'Recover Your Application — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@section('content')
<div style="max-width:30rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">Forgot Your Reference or Password?</h1>
      <p class="subtitle">Answer a few questions about the applicant to look it up.</p>
    </div>
  </div>

  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif

  @if(($checked ?? false) && $matched)
    <div class="card" style="text-align:center;margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Found it</h3>
      <div class="muted" style="font-size:.8rem">Reference number</div>
      <div style="font-weight:800;font-size:1.3rem;margin-bottom:.6rem">{{ $matched->admission_number }}</div>
      <p class="muted">{{ $matched->recoveryCredentialLine() }}</p>
      <a class="btn btn-primary btn-sm" style="margin-top:1rem" href="{{ route('school.admissions.login', ['school' => $school->slug]) }}">Sign in now</a>
    </div>
  @elseif($checked ?? false)
    <div class="card" style="text-align:center;margin-bottom:1rem">
      <p class="muted" style="margin-bottom:1rem">We couldn't automatically match those details to an application on file.</p>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.recover.request', ['school' => $school->slug]) }}">Ask the school to check manually</a>
    </div>
  @endif

  <div class="card">
    <form method="POST" action="{{ route('school.admissions.recover.verify', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="first_name">Applicant's first name</label>
        <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required />
      </div>
      <div class="field">
        <label for="last_name">Applicant's last name</label>
        <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required />
      </div>
      <div class="field">
        <label for="date_of_birth">Applicant's date of birth</label>
        <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" required />
      </div>
      <div class="field">
        <label for="phone_or_email">Parent/guardian phone number or email</label>
        <input id="phone_or_email" name="phone_or_email" type="text" value="{{ old('phone_or_email') }}" required />
      </div>
      <button class="btn btn-primary btn-block" type="submit">Check</button>
    </form>
  </div>

  <p class="support-line" style="margin-top:1rem;text-align:center">
    <a href="{{ route('school.admissions.login', ['school' => $school->slug]) }}">Back to sign in</a>
  </p>
</div>
@endsection
