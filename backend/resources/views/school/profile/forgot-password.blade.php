@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Forgot Password')
@section('bare', true)

@section('content')
<div class="login-page">
  <div style="min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem">
    <div class="form-card" style="width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)">
      <div class="lp-head" style="font-size:1.4rem;font-weight:800;text-align:center">Forgot your password?</div>
      <p class="lp-sub" style="color:var(--mut);margin:.5rem 0 1.2rem;text-align:center">Two ways to get back in.</p>

      @if($errors->any())
        <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
      @elseif(session('status'))
        <div class="flash flash-ok" style="margin-bottom:1rem">{{ session('status') }}</div>
      @endif

      <div class="fgroup" style="margin-bottom:.4rem">Option 1: pick your own password</div>
      <p class="muted" style="margin-bottom:.8rem">{{ $school->name }}'s admin approves it before you can sign in with it, your current password keeps working until then.</p>
      <form method="POST" action="{{ route('school.password.forgot.submit', ['school' => $school->slug]) }}" style="margin-bottom:1.6rem">
        @csrf
        <div class="field">
          <label for="identifier">Email, phone, or admission number</label>
          <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required />
        </div>
        <div class="field">
          <label for="password">New password you want</label>
          <input id="password" name="password" type="password" minlength="8" required />
        </div>
        <div class="field">
          <label for="password_confirmation">Confirm new password</label>
          <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" required />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Send for approval</button>
      </form>

      <div class="fgroup" style="margin-bottom:.4rem">Option 2: email me a reset link</div>
      <p class="muted" style="margin-bottom:.8rem">Applies right away, no approval needed, but only works if your account has an email on file.</p>
      <form method="POST" action="{{ route('school.password.email-link', ['school' => $school->slug]) }}">
        @csrf
        <div class="field" style="margin-bottom:.6rem">
          <label for="link_email">Email</label>
          <input id="link_email" name="email" type="email" required />
        </div>
        <button class="btn btn-ghost btn-block" type="submit">Email me a reset link</button>
      </form>

      <p class="support-line" style="margin-top:1rem;text-align:center">
        <a href="{{ route('school.login', ['school' => $school->slug]) }}">Back to sign in</a>
      </p>
    </div>
  </div>
</div>
@endsection
