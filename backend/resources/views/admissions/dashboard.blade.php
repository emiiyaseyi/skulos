@extends('layouts.app', ['portal' => 'admissions', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'My Application'])
@section('title', 'My Application')
@section('bare', true)

@section('content')
<div style="max-width:40rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">Welcome, {{ $applicant->name }}</h1>
      <p class="subtitle">Your application to {{ $school->name }} is in progress.</p>
    </div>
    <form method="POST" action="{{ route('school.admissions.logout', ['school' => $school->slug]) }}">
      @csrf
      <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
    </form>
  </div>

  <div class="card">
    <p class="muted">Your application window is open until {{ $applicant->expires_at?->toFormattedDateString() ?? 'further notice' }}.</p>
  </div>
</div>
@endsection
