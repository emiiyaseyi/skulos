@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Onboard New Students'])
@section('title', 'Onboard New Students')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Onboard New Students</h1>
    <p class="subtitle">Children assigned to your class after accepting an offer of admission and paying school fees, awaiting your final confirmation before they join your class.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1rem">{{ session('status') }}</div>
@endif

<div class="card">
  @if($students->isEmpty())
    <p class="muted" style="margin-bottom:0">Nothing awaiting onboarding right now.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        <th>Student</th><th>Admission No.</th><th>Class</th><th>Fees</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($students as $student)
          <tr>
            <td data-label="Student">{{ $student->fullName() }}</td>
            <td data-label="Admission No.">{{ $student->admission_number }}</td>
            <td data-label="Class">{{ $student->currentClassArm?->label() ?? 'N/A' }}</td>
            <td data-label="Fees">
              @if($student->admission_fees_cleared_at)
                <span class="badge badge-ok">Cleared</span>
              @else
                <span class="badge badge-warn">Pending</span>
              @endif
            </td>
            <td>
              <a class="btn btn-primary btn-sm" href="{{ route('school.admissions.onboarding.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">Review</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
