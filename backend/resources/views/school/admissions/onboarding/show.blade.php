@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Onboard '.$student->fullName()])
@section('title', 'Onboard '.$student->fullName())

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Onboard {{ $student->fullName() }}</h1>
    <p class="subtitle">Confirm this child's details, optionally assign a house, then add them to your class.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.onboarding.index', ['school' => $school->slug]) }}">Back to queue</a>
</div>

<div class="card" style="margin-bottom:1rem">
  <h3 style="margin-bottom:.6rem">Student details</h3>
  <div class="stack" style="gap:.4rem">
    <div><b>Name</b> {{ $student->fullName() }}</div>
    <div><b>Admission number</b> {{ $student->admission_number }}</div>
    <div><b>Class</b> {{ $student->currentClassArm?->label() ?? 'N/A' }}</div>
    @if($student->date_of_birth)<div><b>Date of birth</b> {{ $student->date_of_birth->toFormattedDateString() }}</div>@endif
    @if($student->gender)<div><b>Gender</b> {{ $student->gender }}</div>@endif
  </div>

  @if($student->guardians->isNotEmpty())
    <h3 style="margin:1rem 0 .6rem">Guardian(s)</h3>
    <div class="stack" style="gap:.3rem">
      @foreach($student->guardians as $guardian)
        <div class="muted">{{ $guardian->name }} ({{ $guardian->relationship ?: 'Guardian' }}) &middot; {{ $guardian->phone ?: $guardian->email ?: 'No contact on file' }}</div>
      @endforeach
    </div>
  @endif
</div>

<div class="card">
  <h3 style="margin-bottom:.6rem">Fees</h3>
  @if($student->admission_fees_cleared_at)
    <p class="flash flash-ok" style="margin-bottom:1rem">School fees cleared on {{ $student->admission_fees_cleared_at->toFormattedDateString() }}. This student can now be added to your class.</p>

    <form method="POST" action="{{ route('school.admissions.onboarding.onboard', ['school' => $school->slug, 'studentId' => $student->id]) }}">
      @csrf
      @if($houses->isNotEmpty())
        <div class="field">
          <label for="house_id">House (optional)</label>
          <select id="house_id" name="house_id">
            <option value="">No house</option>
            @foreach($houses as $house)
              <option value="{{ $house->id }}">{{ $house->name }}</option>
            @endforeach
          </select>
        </div>
      @endif
      <button class="btn btn-primary" type="submit">Confirm and add to class</button>
    </form>
  @else
    <p class="flash flash-bad" style="margin-bottom:0">This student's school fees have not been cleared yet. They cannot be onboarded until payment is confirmed by the school.</p>
  @endif
</div>
@endsection
