@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Complete Enrollment'])
@section('title', 'Complete Enrollment')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Complete Enrollment &middot; {{ $applicant->name }}</h1>
    <p class="subtitle">Confirm the real admission number and class stream. Everything else the applicant already gave carries over automatically.</p>
  </div>
</div>

@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <p><strong>Contact:</strong> {{ $applicant->phone }}{{ $applicant->email ? ' · '.$applicant->email : '' }}</p>
  <p><strong>Applying for:</strong> {{ $applicant->schoolClass?->name ?? 'Not set' }}</p>
</div>

@if(! $acceptanceVerified)
  <div class="card">
    <p class="muted">The acceptance fee has not been verified yet. <a href="{{ route('school.admissions.fees.index', ['school' => $school->slug]) }}">Review fee payments</a> before enrollment can be completed.</p>
  </div>
@elseif($classArms->isEmpty())
  <div class="card">
    <p class="muted">No class streams exist for {{ $applicant->schoolClass?->name ?? 'this class' }} yet. Set one up under Classes &amp; Arms first.</p>
  </div>
@else
  <div class="card">
    <form method="POST" action="{{ route('school.admissions.pipeline.convert', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}">
      @csrf
      <div class="field">
        <label for="admission_number">Admission number</label>
        <input id="admission_number" name="admission_number" value="{{ old('admission_number', $applicant->admission_number) }}" required />
      </div>
      <div class="field">
        <label for="class_arm_id">Class stream</label>
        <select id="class_arm_id" name="class_arm_id" required>
          <option value="">Choose a stream</option>
          @foreach($classArms as $arm)
            <option value="{{ $arm->id }}">{{ $arm->label() }}</option>
          @endforeach
        </select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Enroll {{ $applicant->name }}</button>
    </form>
  </div>
@endif
@endsection
