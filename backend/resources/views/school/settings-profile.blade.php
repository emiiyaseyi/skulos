@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'School Profile'])
@section('title', 'School Profile')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">School Profile</h1>
    <p class="subtitle">Your school's own institutional details.</p>
  </div>
</div>

<div class="card" style="max-width:34rem">
  <div class="field">
    <label>School name</label>
    <input value="{{ $school->name }}" disabled />
    <p class="hint">Contact SKULOS to change your school's name.</p>
  </div>

  <form method="POST" action="{{ route('school.settings.profile.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="motto">Motto</label>
      <input id="motto" name="motto" maxlength="150" value="{{ old('motto', $school->motto) }}" />
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" maxlength="150" value="{{ old('email', $school->email) }}" />
    </div>
    <div class="field">
      <label for="phone">Phone</label>
      <input id="phone" name="phone" maxlength="40" value="{{ old('phone', $school->phone) }}" />
    </div>
    <div class="field">
      <label for="address">Address</label>
      <textarea id="address" name="address" rows="3" maxlength="500">{{ old('address', $school->address) }}</textarea>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
