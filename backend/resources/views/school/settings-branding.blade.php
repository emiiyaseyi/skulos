@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Branding'])
@section('title', 'School Branding')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">School branding</h1>
    <p class="subtitle">Tune how your school's welcome and login page looks to your community.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">Back to dashboard</a>
</div>

@php($b = $school->branding())
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <form method="POST" action="{{ route('school.branding.update', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label for="welcome_headline">Welcome headline</label>
        <input id="welcome_headline" name="welcome_headline" value="{{ old('welcome_headline', $school->welcome_headline) }}" placeholder="Welcome to {{ $school->name }}" />
        @error('welcome_headline')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="welcome_message">Welcome message</label>
        <textarea id="welcome_message" name="welcome_message" rows="3" placeholder="Sign in to continue to your school portal.">{{ old('welcome_message', $school->welcome_message) }}</textarea>
        @error('welcome_message')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="brand_color">Brand colour</label>
        <input id="brand_color" name="brand_color" type="text" value="{{ old('brand_color', $b['color']) }}" placeholder="#E8A33D" />
        <div class="hint">A hex colour like #1B87D6. Used for buttons and accents on your pages.</div>
        @error('brand_color')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="logo">Logo</label>
        <input id="logo" name="logo" type="file" accept="image/*" />
        <div class="hint">A square image works best. Up to 2 MB.</div>
        @error('logo')<div class="err">{{ $message }}</div>@enderror
      </div>
      <button class="btn btn-primary" type="submit">Save branding</button>
    </form>
  </div>

  <div class="card" style="text-align:center;background:var(--bg)">
    <p class="hint" style="text-transform:uppercase;letter-spacing:.08em;margin-bottom:1rem">Live preview</p>
    <div style="background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:2rem">
      <div class="auth-logo" style="margin-inline:auto;background:{{ $b['color'] }}">{{ strtoupper(substr($school->name,0,1)) }}</div>
      <h2 style="font-size:1.3rem;font-weight:800;margin-bottom:.3rem">{{ $b['headline'] }}</h2>
      <p class="muted" style="margin-bottom:1.2rem">{{ $b['message'] }}</p>
      <span class="btn btn-primary btn-block" style="background:{{ $b['color'] }}">Sign in</span>
    </div>
    <p class="hint" style="margin-top:1rem">Your page lives at {{ $school->slug }}.{{ config('app.domain','localhost') }}</p>
  </div>
</div>
@endsection
