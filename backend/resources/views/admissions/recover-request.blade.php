@extends('layouts.app', ['accent' => $accent])
@section('title', 'Request Manual Recovery — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@section('content')
<div style="max-width:30rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">Ask the School to Check Manually</h1>
      <p class="subtitle">Share what you remember about the application. The school will review it and, if it matches, email your reference number and password.</p>
    </div>
  </div>

  @if(session('status'))
    <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif

  <div class="card">
    <form method="POST" action="{{ route('school.admissions.recover.request.submit', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="remembered_name">Applicant's name (as best you remember it)</label>
        <input id="remembered_name" name="remembered_name" type="text" value="{{ old('remembered_name') }}" />
      </div>
      <div class="field">
        <label for="description">What else can you tell us? (class applying for, approximate date applied, parent's name, etc)</label>
        <textarea id="description" name="description" rows="4" required>{{ old('description') }}</textarea>
      </div>
      <div class="field">
        <label for="email">Email address to send your details to</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required />
      </div>
      <button class="btn btn-primary btn-block" type="submit">Submit</button>
    </form>
  </div>

  <p class="support-line" style="margin-top:1rem;text-align:center">
    <a href="{{ route('school.admissions.recover.show', ['school' => $school->slug]) }}">Try the automatic check again</a>
  </p>
</div>
@endsection
