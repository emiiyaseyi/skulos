@extends('layouts.app', ['accent' => null])
@section('title', 'SKULOS Forgot Password')
@section('bare', true)

@section('content')
<div class="login-page">
  <div style="min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem">
    <div class="form-card" style="width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)">
      <div class="lp-head" style="font-size:1.4rem;font-weight:800;text-align:center">Forgot your password?</div>
      <p class="lp-sub" style="color:var(--mut);margin:.5rem 0 1.2rem;text-align:center">We will email a reset link to your platform administrator account.</p>

      @if($errors->any())
        <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
      @elseif(session('status'))
        <div class="flash flash-ok" style="margin-bottom:1rem">{{ session('status') }}</div>
      @endif

      <form method="POST" action="{{ route('login.forgot.submit') }}">
        @csrf
        <div class="field" style="margin-bottom:.6rem">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required autofocus />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Email me a reset link</button>
      </form>

      <p class="support-line" style="margin-top:1rem;text-align:center">
        <a href="{{ route('login') }}">Back to sign in</a>
      </p>
    </div>
  </div>
</div>
@endsection
