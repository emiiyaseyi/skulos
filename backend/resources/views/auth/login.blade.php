@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Sign In')
@section('bare', true)
@section('content')
<div class="auth-shell">
  <div class="auth-card">
    @if($school)
      @php($b = $school->branding())
      <div class="auth-logo">{{ strtoupper(substr($school->name,0,1)) }}</div>
      <h1 class="title" style="font-size:1.4rem">{{ $b['headline'] }}</h1>
      <p class="muted" style="margin:.35rem 0 1.4rem">{{ $b['message'] }}</p>
    @else
      <div class="brand" style="font-size:1.6rem;margin-bottom:.3rem">SK<em>U</em>LOS</div>
      <p class="muted" style="margin-bottom:1.4rem">Sign in to your platform account.</p>
    @endif

    @if($errors->any())
      <div class="flash flash-bad">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ $school ? route('school.login.submit', ['school' => $school->slug]) : url('/login') }}">
      @csrf
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus />
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
      </div>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>

    @unless($school)
      <p class="hint" style="margin-top:1rem">Parents use the email address on their child's record. Staff use the email their school registered.</p>
    @endunless
  </div>
</div>
@endsection
