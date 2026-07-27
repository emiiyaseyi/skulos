@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => "{$student->fullName()} - Academic Report"])
@section('title', "{$student->fullName()} - Academic Report")

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Academic report</h1>
    <p class="subtitle">{{ $student->fullName() }} &middot; {{ $student->admission_number }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canOpenFullReportCard)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.show', ['school' => $school->slug, 'studentId' => $student->id, 'termId' => $term->id]) }}" target="_blank" rel="noopener">View full report card (PDF)</a>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">Back to profile</a>
  </div>
</div>

{{--
  User request 2026-07-26: "parent should not be able to see report
  cards that are yet to be release under the child Academic report,
  also parent should be able to switch to previous terms report cards
  that has been release, add a comment to be passed to the teacher/
  school admin if there's a comment based on the child term report but
  its optional." $availableTerms is already scoped to only released
  terms for a parent/student (StudentController::academicReport()), so
  the switcher below can never expose an unreleased term either way.
--}}
<div class="card" id="report-feedback">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:.4rem">
    <h3 style="margin:0">Scores</h3>
    @if($availableTerms->count() > 1)
      <form method="GET" action="{{ route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="margin:0">
        <select name="term_id" onchange="this.form.requestSubmit()" aria-label="Choose a term">
          @foreach($availableTerms as $t)
            <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->name }}</option>
          @endforeach
        </select>
      </form>
    @endif
  </div>

  @if($isFamilyScoped && $currentTerm && ! $currentTermReleased)
    <p class="muted" style="margin-bottom:.6rem">{{ $currentTerm->name }}&rsquo;s report card has not been released yet.@if($term && $term->id !== $currentTerm->id) Showing {{ $term->name }} instead. @endif</p>
  @endif

  @if(! $term)
    <p class="muted">{{ $isFamilyScoped ? 'No report card has been released yet.' : 'No current term is set for your school yet.' }}</p>
  @elseif(empty($report))
    <p class="muted">No scores recorded yet for {{ $term->name }}.</p>
  @else
    <p class="muted" style="margin-bottom:.8rem">{{ $term->name }}</p>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Subject</th><th>Total</th><th>Grade</th>@if($showRemark)<th>Remark</th>@endif</tr></thead>
      <tbody>
        @foreach($report as $subject => $result)
          <tr>
            <td><strong>{{ $subject }}</strong></td>
            <td>{{ $result['total'] }}</td>
            <td>{{ $result['grade'] ?? 'Not graded' }}</td>
            @if($showRemark)<td class="muted">{{ $result['remark'] ?? 'Not set' }}</td>@endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif

  @if($isFamilyScoped && $term && $canSeeThisTermsReport)
    <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid var(--line)">
      @if($existingFeedback)
        <p class="muted" style="font-size:.85rem">You already sent a comment on {{ $term->name }}&rsquo;s report: &ldquo;{{ $existingFeedback->message }}&rdquo;</p>
      @else
        <form method="POST" action="{{ route('school.students.report-feedback.store', ['school' => $school->slug, 'studentId' => $student->id]) }}">
          @csrf
          <input type="hidden" name="term_id" value="{{ $term->id }}" />
          <div class="field" style="margin-bottom:.5rem">
            <label for="report_feedback_message">Comment on this report (optional)</label>
            <textarea id="report_feedback_message" name="message" rows="3" placeholder="Anything you would like the school to know about this report card?" maxlength="1000"></textarea>
          </div>
          <button class="btn btn-ghost btn-sm" type="submit">Send comment to the school</button>
        </form>
      @endif
    </div>
  @endif
</div>
@endsection
