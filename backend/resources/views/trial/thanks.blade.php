@extends('layouts.app')
@section('title', 'Thank You')
@section('bare', true)

@push('head')
<style>
  .trial-shell{min-height:100vh;display:grid;place-items:center;padding:2.5rem 1.25rem}
  .trial-card{width:100%;max-width:28rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.4rem 2.2rem;box-shadow:var(--shadow);text-align:center}
  .trial-card h1{font-size:1.4rem;font-weight:800;margin:.9rem 0 .5rem}
  .trial-card p{color:var(--mut);line-height:1.6}
</style>
@endpush

@section('content')
<div class="trial-shell">
  <div class="trial-card">
    <div class="auth-logo" style="margin-inline:auto">S</div>
    <h1>Thank you</h1>
    <p>Your trial request has been received. Our team reviews every request and will email you with your setup status, a demo, and training for your staff.</p>
    <a href="/" class="btn btn-primary" style="margin-top:1.4rem">Back to homepage</a>
  </div>
</div>
@endsection
