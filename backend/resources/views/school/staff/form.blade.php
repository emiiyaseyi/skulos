@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $staffMember->exists ? 'Edit Staff' : 'Add Staff'])
@section('title', $staffMember->exists ? 'Edit Staff' : 'Add Staff')

@push('head')
<style>
  .req::after{ content:" *"; color:var(--bad); }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $staffMember->exists ? 'Edit '.$staffMember->fullName() : 'Add Staff' }}</h1>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ $staffMember->exists ? route('school.staff.show', ['school' => $school->slug, 'staffId' => $staffMember->id]) : route('school.staff.index', ['school' => $school->slug]) }}">Cancel</a>
</div>

<div class="card" style="max-width:44rem">
  <form method="POST" action="{{ $staffMember->exists ? route('school.staff.update', ['school' => $school->slug, 'staffId' => $staffMember->id]) : route('school.staff.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label class="req" for="first_name">First Name</label>
        <input id="first_name" name="first_name" value="{{ old('first_name', $staffMember->first_name) }}" required />
        @error('first_name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label class="req" for="last_name">Last Name</label>
        <input id="last_name" name="last_name" value="{{ old('last_name', $staffMember->last_name) }}" required />
        @error('last_name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="other_names">Other Names</label>
        <input id="other_names" name="other_names" value="{{ old('other_names', $staffMember->other_names) }}" />
      </div>
      <div class="field">
        <label for="gender">Gender</label>
        <select id="gender" name="gender">
          <option value="">Not set</option>
          <option value="Male" @selected(old('gender', $staffMember->gender) === 'Male')>Male</option>
          <option value="Female" @selected(old('gender', $staffMember->gender) === 'Female')>Female</option>
        </select>
      </div>
      <div class="field">
        <label class="req" for="position">Position</label>
        <input id="position" name="position" value="{{ old('position', $staffMember->position) }}" placeholder="e.g. Class Teacher, Bursar, Librarian" required />
        @error('position')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status" required>
          @foreach(['active' => 'Active', 'on_leave' => 'On leave', 'suspended' => 'Suspended', 'resigned' => 'Resigned'] as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $staffMember->status ?: 'active') === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label @class(['req' => $staffIdentifier === 'email']) for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $staffMember->email) }}" @required($staffIdentifier === 'email') />
        <div class="hint">Needed later to create this staff member's login.</div>
        @error('email')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => $staffIdentifier === 'phone']) for="phone">Phone</label>
        <input id="phone" name="phone" value="{{ old('phone', $staffMember->phone) }}" @required($staffIdentifier === 'phone') />
        @error('phone')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="date_of_birth">Date of Birth</label>
        <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', optional($staffMember->date_of_birth)->toDateString()) }}" />
      </div>
      <div class="field">
        <label for="employed_on">Employed On</label>
        <input id="employed_on" name="employed_on" type="date" value="{{ old('employed_on', optional($staffMember->employed_on)->toDateString()) }}" />
      </div>
      <div class="field">
        <label for="qualification">Qualification</label>
        <input id="qualification" name="qualification" value="{{ old('qualification', $staffMember->qualification) }}" />
      </div>
      @include('school.partials.custom-fields', ['customFields' => $customFields, 'customValues' => $customValues])
    </div>
    <div class="field">
      <label for="address">Address</label>
      <textarea id="address" name="address" rows="2">{{ old('address', $staffMember->address) }}</textarea>
    </div>
    <button class="btn btn-primary" type="submit">{{ $staffMember->exists ? 'Save changes' : 'Add staff' }}</button>
  </form>
</div>
@endsection
