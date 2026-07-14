@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Send Announcement'])
@section('title', 'Send Announcement')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Send Announcement</h1>
    <p class="subtitle">Reaches every matched recipient in-app immediately, and by email for the audiences your school has email turned on for.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.announcements.index', ['school' => $school->slug]) }}">Back to announcements</a>
</div>

<div class="card" style="max-width:38rem">
  <form method="POST" action="{{ route('school.announcements.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" required maxlength="150" />
    </div>
    <div class="field">
      <label for="body">Message</label>
      <textarea id="body" name="body" rows="8" required maxlength="5000"></textarea>
    </div>
    <div class="field">
      <label for="audience">Audience</label>
      <select id="audience" name="audience" onchange="document.getElementById('classArmField').style.display = this.value === 'class' ? '' : 'none'">
        <option value="all">Everyone</option>
        <option value="parents">Parents</option>
        <option value="teachers">Teachers</option>
        <option value="staff">All staff</option>
        <option value="students">Students</option>
        <option value="class">One class</option>
      </select>
    </div>
    <div class="field" id="classArmField" style="display:none">
      <label for="class_arm_id">Class</label>
      <select id="class_arm_id" name="class_arm_id">
        <option value="">Choose a class</option>
        @foreach($classArms as $arm)
          <option value="{{ $arm->id }}">{{ $arm->label() }}</option>
        @endforeach
      </select>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Send now</button>
  </form>
</div>
@endsection
