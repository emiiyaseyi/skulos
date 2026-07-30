@extends('layouts.app')
@section('title', 'Start a Trial')
@section('bare', true)

@push('head')
<style>
  .trial-shell{min-height:100vh;display:grid;place-items:center;padding:2.5rem 1.25rem}
  .trial-card{width:100%;max-width:34rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.4rem 2.2rem;box-shadow:var(--shadow)}
  .trial-card .auth-logo{margin:0 auto 1.1rem}
  .trial-card h1{text-align:center;font-size:1.4rem;font-weight:800}
  .trial-card p.lead{text-align:center;color:var(--mut);margin:.4rem 0 1.6rem}
  .trial-powered{margin-top:1.4rem;text-align:center;color:var(--mut-dim);font-size:.85rem}
  .trial-powered b em{font-style:normal;color:var(--accent)}
</style>
@endpush

@section('content')
<div class="trial-shell">
  <div class="trial-card">
    <div class="auth-logo" style="margin-inline:auto">S</div>
    <h1>Start a trial</h1>
    <p class="lead">Tell us about your school. We review every request and reply with your setup status, a demo, and training for your staff.</p>

    @if($errors->any())
      <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
    @endif
    @if(session('error'))
      <div class="flash flash-bad" style="margin-bottom:1rem">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('trial.store') }}">
      @csrf
      {{-- honeypot: hidden from real visitors, bots tend to fill every field --}}
      <div style="position:absolute;left:-9999px" aria-hidden="true">
        <label for="website">Leave blank</label>
        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off" />
      </div>

      <div class="field">
        <label for="school_name">School name</label>
        <input id="school_name" name="school_name" value="{{ old('school_name') }}" required />
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label for="contact_name">Your name</label>
          <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" required />
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" required />
        </div>
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label for="phone">Phone (optional)</label>
          <input id="phone" name="phone" value="{{ old('phone') }}" />
        </div>
        <div class="field">
          <label for="student_count">Roughly how many students</label>
          <input id="student_count" name="student_count" type="number" min="1" value="{{ old('student_count') }}" />
        </div>
      </div>
      <div class="field">
        <label for="message">Anything else we should know (optional)</label>
        <textarea id="message" name="message" rows="3">{{ old('message') }}</textarea>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Request a trial</button>
    </form>
    <p class="trial-powered">Powered by <b>SK<em>U</em>LOS</b></p>
  </div>
</div>
@endsection
