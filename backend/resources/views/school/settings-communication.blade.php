@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Communication Settings'])
@section('title', 'Communication Settings')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Communication settings</h1>
    <p class="subtitle">Control how staff and families can message each other.</p>
  </div>
</div>
<div class="card" style="max-width:32rem">
  @if(session('status'))
    <div class="flash flash-ok" style="margin-bottom:1rem">{{ session('status') }}</div>
  @endif
  <form method="POST" action="{{ route('school.settings.communication.update', ['school' => $school->slug]) }}">
    @csrf
    <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:.4rem">
      <input type="checkbox" name="teacher_parent_messaging_enabled" value="1" style="width:auto" @checked($teacherParentMessagingEnabled) /> Teachers can message parents
    </label>
    <p class="muted" style="font-size:.85rem;margin-bottom:1rem">When off, a teacher cannot start or continue a chat with a parent. A parent can still message a teacher and reply to any existing conversation.</p>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
