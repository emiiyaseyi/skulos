@extends('layouts.app', ['accent' => $accent])
@section('title', 'Seek Admission — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@section('content')
<div style="max-width:34rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">Seek Admission</h1>
      <p class="subtitle">Apply for admission to {{ $school->name }}.</p>
    </div>
  </div>

  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif

  @if($windows->isEmpty())
    <div class="card">
      <p class="muted">Admissions are not currently open. Please check back later or contact the school directly.</p>
    </div>
  @else
    <div class="card">
      <form method="POST" action="{{ route('school.admissions.apply.submit', ['school' => $school->slug]) }}">
        @csrf
        <div class="field">
          <label for="admission_window_id">Class applying for</label>
          <select id="admission_window_id" name="admission_window_id" required>
            <option value="">Choose an open window</option>
            @foreach($windows as $window)
              <option value="{{ $window->id }}" @selected(old('admission_window_id') == $window->id)>
                {{ $window->schoolClass?->name }} &middot; {{ $window->session?->name }}
                @if($window->campus) &middot; {{ $window->campus->name }} @endif
                (&#8358;{{ number_format($window->application_fee_amount, 2) }} application fee)
              </option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="name">Applicant's full name</label>
          <input id="name" name="name" type="text" value="{{ old('name') }}" required />
        </div>
        <div class="field">
          <label for="phone">Parent/guardian phone number</label>
          <input id="phone" name="phone" type="text" value="{{ old('phone') }}" required autocomplete="tel" />
          <div class="hint">This becomes part of how you sign back in later, along with a password issued once the application fee is confirmed.</div>
        </div>
        <div class="field">
          <label for="email">Parent/guardian email (optional)</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Start application</button>
      </form>
    </div>
  @endif

  <p class="support-line" style="margin-top:1rem;text-align:center">
    Already applied? <a href="{{ route('school.admissions.login', ['school' => $school->slug]) }}">Continue your application</a>
  </p>
</div>
@endsection
