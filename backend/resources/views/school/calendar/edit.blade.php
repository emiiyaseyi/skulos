@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Edit Event'])
@section('title', 'Edit Event')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Edit Event</h1>
    <p class="subtitle">{{ $event->is_default ? 'A default notable day. Set or correct its date below.' : 'Update this calendar entry.' }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.calendar.index', ['school' => $school->slug]) }}">Back to calendar</a>
</div>

<div class="card" style="max-width:32rem">
  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif
  <form method="POST" action="{{ route('school.calendar.update', ['school' => $school->slug, 'eventId' => $event->id]) }}">
    @csrf
    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" required maxlength="150" value="{{ old('title', $event->title) }}" />
    </div>
    <div class="field">
      <label for="category">Category</label>
      <select id="category" name="category" onchange="document.getElementById('examFields').style.display=this.value==='exam'?'block':'none'">
        @foreach($categories as $cat)
          <option value="{{ $cat }}" @selected(old('category', $event->category) === $cat)>{{ ucfirst($cat) }}</option>
        @endforeach
      </select>
    </div>
    <div id="examFields" style="display:{{ $event->category === 'exam' ? 'block' : 'none' }}">
      <div class="field">
        <label for="subject_id">Subject</label>
        <select id="subject_id" name="subject_id">
          <option value="">Select a subject</option>
          @foreach($subjects as $subject)
            <option value="{{ $subject->id }}" @selected(old('subject_id', $event->subject_id) == $subject->id)>{{ $subject->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="class_arm_id">Class</label>
        <select id="class_arm_id" name="class_arm_id">
          <option value="">Select a class</option>
          @foreach($classArms as $arm)
            <option value="{{ $arm->id }}" @selected(old('class_arm_id', $event->class_arm_id) == $arm->id)>{{ $arm->label() }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div class="field">
      <label for="starts_on">Starts</label>
      <input id="starts_on" name="starts_on" type="date" required value="{{ old('starts_on', optional($event->starts_on)->toDateString()) }}" />
    </div>
    <div class="field">
      <label for="ends_on">Ends (optional, for a multi day event)</label>
      <input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on', optional($event->ends_on)->toDateString()) }}" />
    </div>
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
      <input type="checkbox" name="all_day" value="1" style="width:auto" @checked(old('all_day', $event->all_day)) /> All day
    </label>
    <div class="field">
      <label for="description">Description (optional)</label>
      <textarea id="description" name="description" rows="4" maxlength="2000">{{ old('description', $event->description) }}</textarea>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
  </form>
</div>
@endsection
