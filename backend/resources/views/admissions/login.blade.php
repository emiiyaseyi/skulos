@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Applicant Sign In')
@section('bare', true)

@push('head')
<style>
  .admissions-login-page{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:2rem 1.25rem}
  .admissions-login-page .form-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)}
  .admissions-login-page .lp-head{font-size:1.4rem;font-weight:800;line-height:1.15;letter-spacing:-.01em;text-align:center}
  .admissions-login-page .lp-sub{color:var(--mut);margin:.4rem 0 1.5rem;text-align:center}
  .adm-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .adm-logo img{width:100%;height:100%;object-fit:contain}
</style>
@endpush

@section('content')
<div class="admissions-login-page">
  <div class="form-card">
    @php($logo = $school?->branding()['logo'] ?? null)
    <div class="adm-logo">
      @if($logo)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="{{ $school->name }} logo" />
      @else
        {{ strtoupper(substr($school->name ?? 'S', 0, 1)) }}
      @endif
    </div>
    <div class="lp-head">Applicant Sign In</div>
    <p class="lp-sub">Sign in with your application reference number or parent phone number to continue your application to {{ $school->name ?? 'this school' }}.</p>

    @if($errors->any())
      <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('school.admissions.login.submit', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="identifier">Application Reference Number or Parent Phone Number</label>
        <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" autocomplete="username" required autofocus />
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
      </div>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
    <p class="support-line" style="margin-top:1rem;text-align:center">
      <a href="{{ route('school.admissions.recover.show', ['school' => $school->slug]) }}">Forgot your reference number or password?</a>
    </p>
  </div>
</div>
@endsection
