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

  <form method="POST" action="{{ route('school.settings.profile.update', ['school' => $school->slug]) }}" enctype="multipart/form-data">
    @csrf

    <div class="field">
      <label for="logo">Logo</label>
      @if($school->logo_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($school->logo_path) }}" alt="Current school logo" style="height:56px;display:block;margin-bottom:.4rem" />
      @endif
      <input id="logo" name="logo" type="file" accept="image/*" />
      <p class="hint">A square image works best. Up to 2 MB. Shared with Branding, changing it here updates it everywhere.</p>
    </div>

    <div class="field">
      <label for="motto">Motto</label>
      <input id="motto" name="motto" maxlength="150" value="{{ old('motto', $school->motto) }}" />
    </div>

    <div class="grid cols-2">
      <div class="field">
        <label for="registration_number">Registration number</label>
        <input id="registration_number" name="registration_number" maxlength="100" value="{{ old('registration_number', $school->registration_number) }}" />
      </div>
      <div class="field">
        <label for="established_year">Year established</label>
        <input id="established_year" name="established_year" type="number" min="1800" max="{{ now()->year }}" value="{{ old('established_year', $school->established_year) }}" />
      </div>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" maxlength="150" value="{{ old('email', $school->email) }}" />
    </div>

    <div class="grid cols-2">
      <div class="field">
        <label for="phone">Phone</label>
        <input id="phone" name="phone" maxlength="40" value="{{ old('phone', $school->phone) }}" />
      </div>
      <div class="field">
        <label for="alternate_phone">Alternate phone</label>
        <input id="alternate_phone" name="alternate_phone" maxlength="40" value="{{ old('alternate_phone', $school->alternate_phone) }}" />
      </div>
    </div>

    <div class="field">
      <label for="website">Website</label>
      <input id="website" name="website" type="url" maxlength="200" placeholder="https://" value="{{ old('website', $school->website) }}" />
    </div>

    <div class="field">
      <label for="address">Address</label>
      <textarea id="address" name="address" rows="3" maxlength="500">{{ old('address', $school->address) }}</textarea>
    </div>

    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
