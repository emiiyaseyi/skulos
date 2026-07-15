@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Post Assignment'])
@section('title', 'Post Assignment')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Post Assignment</h1>
    <p class="subtitle">Add homework or coursework for a subject and class.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.assignments.index', ['school' => $school->slug]) }}">Back to assignments</a>
</div>

<div class="card" style="max-width:32rem">
  <form method="POST" action="{{ route('school.assignments.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" required maxlength="150" />
    </div>
    <div class="field">
      <label for="subject_id">Subject</label>
      <select id="subject_id" name="subject_id" required>
        <option value="">Select a subject</option>
        @foreach($subjects as $subject)
          <option value="{{ $subject->id }}">{{ $subject->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="class_arm_id">Class</label>
      <select id="class_arm_id" name="class_arm_id" required>
        <option value="">Select a class</option>
        @foreach($classArms as $arm)
          <option value="{{ $arm->id }}">{{ $arm->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="due_on">Due date (optional)</label>
      <input id="due_on" name="due_on" type="date" />
    </div>
    <div class="field">
      <label for="description">Description (optional)</label>
      <textarea id="description" name="description" rows="4" maxlength="4000"></textarea>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Post assignment</button>
  </form>
</div>
@endsection
