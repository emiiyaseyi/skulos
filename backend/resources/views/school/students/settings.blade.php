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

<div class="card">
  <form method="POST" action="{{ route('school.students.settings.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="field-grid" style="margin-bottom:1.2rem">
      @foreach($fields as $key => $label)
        <label class="field-check">
          {{ $label }}
          <input type="checkbox" name="required_fields[]" value="{{ $key }}" @checked(in_array($key, $required, true)) />
        </label>
      @endforeach
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
