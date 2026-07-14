@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Create Event'])
@section('title', 'Create Event')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Create Event</h1>
    <p class="subtitle">Add a notable day or event to {{ $school->name }}'s calendar.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.calendar.index', ['school' => $school->slug]) }}">Back to calendar</a>
</div>

<div class="card" style="max-width:32rem">
  <form method="POST" action="{{ route('school.calendar.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" required maxlength="150" />
    </div>
    <div class="field">
      <label for="category">Category</label>
      <select id="category" name="category">
        @foreach($categories as $cat)
          <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="starts_on">Starts</label>
      <input id="starts_on" name="starts_on" type="date" required />
    </div>
    <div class="field">
      <label for="ends_on">Ends (optional, for a multi day event)</label>
      <input id="ends_on" name="ends_on" type="date" />
    </div>
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="all_day" value="1" style="width:auto" checked /> All day
    </label>
    <div class="field">
      <label for="description">Description (optional)</label>
      <textarea id="description" name="description" rows="4" maxlength="2000"></textarea>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add to calendar</button>
  </form>
</div>
@endsection
