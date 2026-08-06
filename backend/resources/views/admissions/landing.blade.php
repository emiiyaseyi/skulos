@extends('layouts.app', ['accent' => $accent])
@section('title', 'Seek Admission — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@push('head')
<style>
  .adm-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .adm-logo img{width:100%;height:100%;object-fit:contain}
  .adm-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-top:1.5rem}
  @media(max-width:36rem){.adm-cards{grid-template-columns:1fr}}
  .adm-card{display:block;text-decoration:none;color:inherit;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.6rem;text-align:center;box-shadow:var(--shadow)}
  .adm-card h3{margin:.6rem 0 .3rem}
</style>
@endpush

@section('content')
<div style="max-width:38rem;margin:3rem auto;padding:0 1.25rem;text-align:center">
  @php($logo = $school->branding()['logo'] ?? null)
  <div class="adm-logo">
    @if($logo)
      <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="{{ $school->name }} logo" />
    @else
      {{ strtoupper(substr($school->name ?? 'S', 0, 1)) }}
    @endif
  </div>
  <h1 class="title" style="margin-bottom:.3rem">Seek Admission</h1>
  <p class="subtitle">{{ $school->name }}</p>

  <div class="adm-cards">
    <a class="adm-card" href="{{ route('school.admissions.apply.start', ['school' => $school->slug]) }}">
      @include('layouts.partials.icon', ['name' => 'students'])
      <h3>Start a new application</h3>
      <p class="muted">Applying to {{ $school->name }} for the first time.</p>
    </a>
    <a class="adm-card" href="{{ route('school.admissions.login', ['school' => $school->slug]) }}">
      @include('layouts.partials.icon', ['name' => 'dashboard'])
      <h3>Continue an existing application</h3>
      <p class="muted">Already applied and have a reference number.</p>
    </a>
  </div>

  <p class="support-line" style="margin-top:1.5rem">
    <a href="{{ route('school.login', ['school' => $school->slug]) }}">Back to sign in</a>
  </p>
</div>
@endsection
