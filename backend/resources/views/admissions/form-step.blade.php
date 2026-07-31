@extends('layouts.app', ['portal' => 'admissions', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Application Form'])
@section('title', 'Application Form')
@section('bare', true)

@section('content')
<div style="max-width:34rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">Application Form</h1>
      <p class="subtitle">Step {{ $stepIndex + 1 }} of {{ $totalSteps }}</p>
    </div>
  </div>

  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif

  <div class="card">
    <form method="POST" action="{{ route('school.admissions.form.save', ['school' => $school->slug, 'step' => $step]) }}">
      @csrf
      @foreach($sections as $section)
        <h3 style="margin-bottom:.6rem">{{ $section->label }}</h3>
        @foreach($section->fields as $field)
          @php($current = $existingValues[$field->id] ?? '')
          @if($field->type === 'geo')
            @php($geo = json_decode($current ?: '{}', true) ?: [])
            <x-geo-picker
              country-name="field_{{ $field->id }}_country"
              state-name="field_{{ $field->id }}_state"
              lga-name="field_{{ $field->id }}_lga"
              :country-value="$geo['country'] ?? null"
              :state-value="$geo['state'] ?? null"
              :lga-value="$geo['lga'] ?? null"
              :country-required="$field->is_required"
            />
          @else
          <div class="field">
            <label for="field_{{ $field->id }}">{{ $field->label }}{{ $field->is_required ? ' *' : '' }}</label>
            @if($field->type === 'textarea')
              <textarea id="field_{{ $field->id }}" name="fields[{{ $field->id }}]" rows="3" @if($field->is_required) required @endif>{{ old("fields.{$field->id}", $current) }}</textarea>
            @elseif($field->type === 'select')
              <select id="field_{{ $field->id }}" name="fields[{{ $field->id }}]" @if($field->is_required) required @endif>
                <option value="">Choose one</option>
                @foreach($field->options ?? [] as $option)
                  <option value="{{ $option }}" @selected(old("fields.{$field->id}", $current) === $option)>{{ $option }}</option>
                @endforeach
              </select>
            @elseif($field->type === 'checkbox')
              <input id="field_{{ $field->id }}" name="fields[{{ $field->id }}]" type="checkbox" value="yes" @checked(old("fields.{$field->id}", $current) === 'yes') />
            @else
              <input id="field_{{ $field->id }}" name="fields[{{ $field->id }}]"
                type="{{ $field->type === 'number' ? 'number' : ($field->type === 'date' ? 'date' : 'text') }}"
                value="{{ old("fields.{$field->id}", $current) }}" @if($field->is_required) required @endif />
            @endif
          </div>
          @endif
        @endforeach
      @endforeach
      <button class="btn btn-primary btn-block" type="submit">{{ $isLastStep ? 'Finish' : 'Continue' }}</button>
    </form>
  </div>
</div>
@endsection
