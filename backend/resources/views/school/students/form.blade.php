@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $student->exists ? 'Edit Student' : 'Add Student'])
@section('title', $student->exists ? 'Edit Student' : 'Add Student')

@push('head')
<style>
  .req::after{ content:" *"; color:var(--bad); }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $student->exists ? 'Edit '.$student->fullName() : 'Add Student' }}</h1>
    <p class="subtitle">Fields marked * are required by your school's <a href="{{ route('school.students.settings.edit', ['school' => $school->slug]) }}">field settings</a>.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ $student->exists ? route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) : route('school.students', ['school' => $school->slug]) }}">Cancel</a>
</div>

<div class="card" style="max-width:44rem">
  <form method="POST" action="{{ $student->exists ? route('school.students.update', ['school' => $school->slug, 'studentId' => $student->id]) : route('school.students.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2">
      <div class="field">
        <label class="req" for="admission_number">Admission Number</label>
        <input id="admission_number" name="admission_number" value="{{ old('admission_number', $student->admission_number) }}" required />
        @error('admission_number')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="email">Email (optional)</label>
        <input id="email" name="email" type="email" value="{{ old('email', $student->email) }}" />
        @error('email')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label class="req" for="first_name">First Name</label>
        <input id="first_name" name="first_name" value="{{ old('first_name', $student->first_name) }}" required />
        @error('first_name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label class="req" for="last_name">Last Name</label>
        <input id="last_name" name="last_name" value="{{ old('last_name', $student->last_name) }}" required />
        @error('last_name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => in_array('other_names', $requiredFields)]) for="other_names">Other Names</label>
        <input id="other_names" name="other_names" value="{{ old('other_names', $student->other_names) }}" @required(in_array('other_names', $requiredFields)) />
        @error('other_names')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => in_array('gender', $requiredFields)]) for="gender">Gender</label>
        <select id="gender" name="gender" @required(in_array('gender', $requiredFields))>
          <option value="">Not set</option>
          <option value="male" @selected(old('gender', $student->gender) === 'male')>Male</option>
          <option value="female" @selected(old('gender', $student->gender) === 'female')>Female</option>
        </select>
        @error('gender')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => in_array('date_of_birth', $requiredFields)]) for="date_of_birth">Date of Birth</label>
        <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', optional($student->date_of_birth)->toDateString()) }}" @required(in_array('date_of_birth', $requiredFields)) />
        @error('date_of_birth')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => in_array('parent_mobile', $requiredFields)]) for="parent_mobile">Parent Mobile</label>
        <input id="parent_mobile" name="parent_mobile" value="{{ old('parent_mobile', $student->parent_mobile) }}" @required(in_array('parent_mobile', $requiredFields)) />
        @error('parent_mobile')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => in_array('class', $requiredFields)]) for="current_class_arm_id">Class</label>
        <select id="current_class_arm_id" name="current_class_arm_id" @required(in_array('class', $requiredFields))>
          <option value="">Not set</option>
          @foreach($classArms as $classArm)
            <option value="{{ $classArm->id }}" @selected((string) old('current_class_arm_id', $student->current_class_arm_id) === (string) $classArm->id)>{{ $classArm->label() }}</option>
          @endforeach
        </select>
        @error('current_class_arm_id')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label @class(['req' => in_array('house', $requiredFields)]) for="house_id">House</label>
        <select id="house_id" name="house_id" @required(in_array('house', $requiredFields))>
          <option value="">Not set</option>
          @foreach($houses as $house)
            <option value="{{ $house->id }}" @selected((string) old('house_id', $student->house_id) === (string) $house->id)>{{ $house->name }}</option>
          @endforeach
        </select>
        @error('house_id')<div class="err">{{ $message }}</div>@enderror
      </div>
      <x-geo-picker
        country-name="country"
        state-name="state_of_origin"
        lga-name="lga"
        :country-value="old('country', $student->country)"
        :state-value="old('state_of_origin', $student->state_of_origin)"
        :lga-value="old('lga', $student->lga)"
        :country-required="in_array('country', $requiredFields)"
        :state-required="in_array('state_of_origin', $requiredFields)"
        :lga-required="in_array('lga', $requiredFields)"
      />
    </div>
    <div class="field">
      <label @class(['req' => in_array('address', $requiredFields)]) for="address">Address</label>
      <textarea id="address" name="address" rows="2" @required(in_array('address', $requiredFields))>{{ old('address', $student->address) }}</textarea>
      @error('address')<div class="err">{{ $message }}</div>@enderror
    </div>
    <button class="btn btn-primary" type="submit">{{ $student->exists ? 'Save changes' : 'Add student' }}</button>
  </form>
</div>
@endsection
