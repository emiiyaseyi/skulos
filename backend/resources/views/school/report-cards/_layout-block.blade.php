{{-- One draggable header/identity block, resolved from the report card's
     own $data/$school (shared automatically from the parent render.blade.php
     view). Used by both the "header" and "info" zones, see ReportCardLayout. --}}
@switch($block)
  @case('logo')
    @if($logoUrl)<img class="rc-logo" src="{{ $logoUrl }}" alt="" />@endif
    @break
  @case('school_name')
    <h1 class="rc-lb-title">{{ $school->name }}</h1>
    @break
  @case('address')
    <p class="rc-lb-text">{{ $school->address ?: $school->motto }}</p>
    @break
  @case('term_session')
    <div class="term">{{ $data['term']['name'] }} Report &middot; {{ $data['term']['session'] }}</div>
    @break
  @case('student_name')
    <div><b>Name</b>{{ $data['identity']['name'] }}</div>
    @break
  @case('admission_number')
    <div><b>Admission No.</b>{{ $data['identity']['admission_number'] }}</div>
    @break
  @case('class')
    <div><b>Class</b>{{ $data['identity']['class'] }}</div>
    @break
  @case('gender')
    <div><b>Gender</b>{{ $data['identity']['gender'] }}</div>
    @break
  @case('date_of_birth')
    <div><b>Date of Birth</b>{{ $data['identity']['date_of_birth'] }}</div>
    @break
  @case('age')
    <div><b>Age</b>{{ $data['identity']['age'] }}</div>
    @break
  @case('house')
    <div><b>House</b>{{ $data['identity']['house'] }}</div>
    @break
  @case('class_teacher')
    <div><b>Class Teacher</b>{{ $data['identity']['class_teacher'] }}</div>
    @break
  @case('attendance')
    <div><b>Attendance</b>{{ $data['attendance']['present'] }} of {{ $data['attendance']['total'] }}</div>
    @break
  @case('next_term_begins')
    <div><b>Next Term Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>
    @break
  @case('overall_position')
    <div><b>Position</b>{{ $data['overall']['position'] ?? 'N/A' }} of {{ $data['overall']['class_size'] ?? 'N/A' }}</div>
    @break
@endswitch
