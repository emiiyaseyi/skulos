@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Promotion Requirement'])
@section('title', 'Promotion Requirement')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Promotion Requirement</h1>
    <p class="subtitle">Set once as part of setup, and editable any time. Used automatically when a term is ended for the last time in a session.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.settings.calendar.edit', ['school' => $school->slug]) }}">Back to Sessions &amp; Terms</a>
</div>

<div class="card" style="max-width:32rem">
  <h3 style="margin-bottom:.6rem">Minimum average to be promoted</h3>
  <p class="muted" style="margin-bottom:.8rem">A student whose overall average for the ending term is at or above this percentage moves up to their next class. Below it, they stay in their current class.</p>
  <form method="POST" action="{{ route('school.academics.promotion-settings.update', ['school' => $school->slug]) }}" style="display:flex;gap:.6rem;align-items:flex-end">
    @csrf
    <div class="field" style="margin-bottom:0;max-width:10rem">
      <label for="promotion_threshold">Minimum average (%)</label>
      <input id="promotion_threshold" name="promotion_threshold" type="number" step="0.1" min="0" max="100" value="{{ $threshold }}" @disabled(! $canManage) required />
    </div>
    @if($canManage)
      <button class="btn btn-primary btn-sm" type="submit">Save</button>
    @endif
  </form>
</div>
@endsection
