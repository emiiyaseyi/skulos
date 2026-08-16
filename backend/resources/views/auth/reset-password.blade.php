@extends('layouts.app', ['accent' => null])
@section('title', 'SKULOS Reset Password')
@section('bare', true)

@section('content')
<div class="login-page">
  <div style="min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:2rem 1.25rem">
    <div class="form-card" style="width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)">
      <div class="lp-head" style="font-size:1.55rem;font-weight:800;text-align:center">Reset your password</div>
      <p class="lp-sub" style="color:var(--mut);margin:.5rem 0 1.4rem;text-align:center">Choose a new platform administrator password.</p>

      @if($errors->any())
        <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ route('login.reset.submit') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}" />
        <input type="hidden" name="email" value="{{ $email }}" />
        <div class="field">
          <label for="password">New password</label>
          <input id="password" name="password" type="password" minlength="8" required autofocus />
        </div>
        <div class="field">
          <label for="password_confirmation">Confirm new password</label>
          <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" required />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Reset password</button>
      </form>
      <p class="support-line" style="margin-top:1rem;text-align:center">
        <a href="{{ route('login') }}">Back to sign in</a>
      </p>
    </div>
  </div>
</div>
@endsection
