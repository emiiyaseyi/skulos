@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'My Profile'])
@section('title', 'My Profile')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">My Profile</h1>
    <p class="subtitle">Your name, email, and phone number.</p>
  </div>
</div>

@if($pending)
  <div class="card" style="margin-bottom:1.25rem;border-color:var(--warn,#c98a1c);max-width:32rem">
    <h3 style="margin-bottom:.4rem">Awaiting approval</h3>
    <p class="muted" style="margin-bottom:.6rem">You have a change waiting on {{ $school->name }}'s admin. It has not taken effect yet.</p>
    <table>
      <thead><tr><th>Field</th><th>Current</th><th>Requested</th></tr></thead>
      <tbody>
        @foreach($pending->requested as $field => $value)
          <tr>
            <td class="muted">{{ ucfirst($field) }}</td>
            <td>{{ $pending->original[$field] ?? '' }}</td>
            <td><strong>{{ $value }}</strong></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

<div class="card" style="max-width:32rem">
  <form method="POST" action="{{ route('school.profile.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="p_name">Full name</label>
      <input id="p_name" name="name" value="{{ old('name', $profileUser->name) }}" required />
      @error('name')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="p_email">Email</label>
      <input id="p_email" name="email" type="email" value="{{ old('email', $profileUser->email) }}" required />
      @error('email')<div class="err">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="p_phone">Phone</label>
      <input id="p_phone" name="phone" value="{{ old('phone', $profileUser->phone) }}" />
      @error('phone')<div class="err">{{ $message }}</div>@enderror
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
    <p class="hint" style="margin-top:.6rem">
      @if($canSelfApply)
        Changes apply immediately.
      @else
        Changes are sent to {{ $school->name }}'s admin for approval before they take effect.
      @endif
    </p>
  </form>
</div>

<div class="card" style="max-width:32rem;margin-top:1.25rem">
  <h3 style="margin-bottom:.4rem">Password</h3>
  <p class="muted" style="margin-bottom:.8rem">Changing your password applies immediately, no approval needed.</p>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.password.change', ['school' => $school->slug]) }}">Change password</a>
</div>
@endsection
