@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Applicant Sign In')
@section('bare', true)

@push('head')
<style>
  .admissions-login-page{min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem}
  .admissions-login-page .form-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)}
  .admissions-login-page .lp-head{font-size:1.4rem;font-weight:800;line-height:1.15;letter-spacing:-.01em;text-align:center}
  .admissions-login-page .lp-sub{color:var(--mut);margin:.4rem 0 1.5rem;text-align:center}
</style>
@endpush

@section('content')
<div class="admissions-login-page">
  <div class="form-card">
    <div class="lp-head">Applicant Sign In</div>
    <p class="lp-sub">Sign in with your admission number or parent phone number to continue your application to {{ $school->name ?? 'this school' }}.</p>

    @if($errors->any())
      <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('school.admissions.login.submit', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="identifier">Admission Number or Parent Phone Number</label>
        <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" autocomplete="username" required autofocus />
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
      </div>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
  </div>
</div>
@endsection
