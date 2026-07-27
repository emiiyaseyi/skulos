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

@if($siblings->count() > 1)
  <div style="margin-bottom:1.25rem;max-width:16rem">
    <select aria-label="Switch to another child" onchange="if(this.value) location.href=this.value">
      @foreach($siblings as $sibling)
        <option value="{{ route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $sibling->id]) }}" @selected($sibling->id === $student->id)>{{ $sibling->fullName() }} &middot; {{ $sibling->currentClassArm?->label() ?? 'No class set' }}</option>
      @endforeach
    </select>
  </div>
@endif

@if($analytics)
  @php
    $tiedHighest = $analytics['highestSubjects']->count() > 1;
    $atRiskCount = $analytics['atRiskSubjects']->count();
  @endphp
  <div class="grid cols-5" style="gap:.8rem;margin-bottom:1.1rem">
    <div class="card stat-card">
      <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'stats'])</span>
      <span class="stat"><b>{{ $analytics['average'] }}%</b><span>{{ $term->name }} average</span></span>
    </div>
    <div class="card stat-card">
      <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'scale'])</span>
      <span class="stat"><b>{{ $analytics['overallGrade'] ?? 'Not graded' }}</b><span>Overall grade</span></span>
    </div>
    <div class="card stat-card">
      <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'insight'])</span>
      @if($tiedHighest)
        <span class="stat">
          <b style="font-size:1rem">{{ $analytics['highestSubjects']->count() }} Subjects Tied</b>
          <span>Top scorers at {{ $analytics['highestScore'] }} &middot; <span style="font-size:.65rem">see full list below</span></span>
        </span>
      @else
        <span class="stat"><b style="font-size:1rem">{{ $analytics['highestSubjects']->first() }}</b><span>Strongest &middot; {{ $analytics['highestScore'] }}</span></span>
      @endif
    </div>
    <div class="card stat-card">
      @if($atRiskCount === 0)
        <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'approve'])</span>
        <span class="stat"><b style="font-size:1rem">Well Rounded</b><span>No subject below {{ $analytics['riskThreshold'] }}%</span></span>
      @elseif($atRiskCount === 1)
        <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'warning'])</span>
        <span class="stat"><b style="font-size:1rem">{{ $analytics['atRiskSubjects']->first() }}</b><span>Needs attention</span></span>
      @else
        <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'warning'])</span>
        <span class="stat">
          <b style="font-size:1rem">{{ $atRiskCount }} Subjects at Risk</b>
          <span>Below {{ $analytics['riskThreshold'] }}% &middot; <span style="font-size:.65rem">see full list below</span></span>
        </span>
      @endif
    </div>
    <div class="card stat-card">
      <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'trend'])</span>
      @if($analytics['previousAverage'] !== null)
        <span class="stat">
          <b style="display:flex;align-items:center;gap:.25rem;color:{{ $analytics['diff'] > 0 ? 'var(--ok)' : ($analytics['diff'] < 0 ? 'var(--bad)' : 'inherit') }}">
            @if($analytics['diff'] > 0)
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
            @elseif($analytics['diff'] < 0)
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
            @endif
            {{ $analytics['diff'] > 0 ? '+' : '' }}{{ $analytics['diff'] }}%
          </b>
          <span>vs {{ $analytics['previousTerm']->name }}</span>
        </span>
      @else
        <span class="stat">
          <b style="font-size:1rem">No report found</b>
          <span>No earlier released term</span>
        </span>
      @endif
    </div>
  </div>
@endif

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
    @if($availableTerms->isNotEmpty())
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
