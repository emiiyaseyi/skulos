@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Student Field Settings'])
@section('title', 'Student Field Settings')

@push('head')
<style>
  .field-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem 1.2rem}
  .field-check{display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.6rem .8rem;border:1px solid var(--line);border-radius:10px;font-size:.88rem;font-weight:600}
  .field-check input{width:auto}
  @media(max-width:640px){.field-grid{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Student Field Settings</h1>
    <p class="subtitle">Choose which student fields must be filled in, for both bulk import and single entry. Admission number, first name, and last name are always required; email is never required.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">Back to Students</a>
</div>

<form method="POST" action="{{ route('school.students.settings.update', ['school' => $school->slug]) }}">
  @csrf
  <div class="card">
    <div class="field-grid" style="margin-bottom:1.2rem">
      @foreach($fields as $key => $label)
        <label class="field-check">
          {{ $label }}
          <input type="checkbox" name="required_fields[]" value="{{ $key }}" @checked(in_array($key, $required, true)) />
        </label>
      @endforeach
    </div>
  </div>

  <div class="card" style="margin-top:1.25rem">
    <h3 style="margin-bottom:.4rem">Parent and staff identifier</h3>
    <p class="muted" style="margin-bottom:.8rem">Choose which contact field uniquely identifies a parent, and which one identifies a staff member. Pick one per person type and stick with it, this is what links the same person across their children or across schools.</p>
    <div class="grid cols-2">
      <div class="field">
        <label for="parent_identifier">Parent identifier</label>
        <select id="parent_identifier" name="parent_identifier">
          @foreach($identifierOptions as $key => $label)
            <option value="{{ $key }}" @selected($parentIdentifier === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="staff_identifier">Staff identifier</label>
        <select id="staff_identifier" name="staff_identifier">
          @foreach($identifierOptions as $key => $label)
            <option value="{{ $key }}" @selected($staffIdentifier === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:1.25rem">
    <h3 style="margin-bottom:.4rem">Guardian details visibility</h3>
    <p class="muted" style="margin-bottom:.8rem">A class teacher can always see their own class's guardian/parent details. Choose whether a subject teacher can too.</p>
    <div class="field" style="max-width:24rem">
      <label for="guardian_visibility">Who can see guardian details</label>
      <select id="guardian_visibility" name="guardian_visibility">
        @foreach($guardianVisibilityOptions as $key => $label)
          <option value="{{ $key }}" @selected($guardianVisibility === $key)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
  </div>

  <button class="btn btn-primary btn-sm" type="submit" style="margin-top:1rem">Save</button>
</form>
@endsection
