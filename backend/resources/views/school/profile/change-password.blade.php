@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Change Password'])
@section('title', 'Change Password')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Change Password</h1>
    @if($forced)
      <p class="subtitle">You are signing in with a temporary password. Choose a new one to continue.</p>
    @else
      <p class="subtitle">Change your own password. This applies immediately.</p>
    @endif
  </div>
  @unless($forced)
    <a class="btn btn-ghost btn-sm" href="{{ route('school.profile.edit', ['school' => $school->slug]) }}">Back to My Profile</a>
  @endunless
</div>

<div class="card" style="max-width:28rem">
  <form method="POST" action="{{ route('school.password.change.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" required autofocus />
      @error('current_password')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password">New password</label>
      <input id="password" name="password" type="password" minlength="8" required />
      @error('password')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password_confirmation">Confirm new password</label>
      <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" required />
    </div>
    <button class="btn btn-primary" type="submit">Change password</button>
  </form>
</div>
@endsection
