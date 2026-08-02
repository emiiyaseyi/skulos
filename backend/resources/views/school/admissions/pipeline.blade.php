@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Applicants'])
@section('title', 'Applicants')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Applicants</h1>
    <p class="subtitle">Every applicant currently in the admission pipeline, by status.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.fees.index', ['school' => $school->slug]) }}">Fee payments</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.document-review.index', ['school' => $school->slug]) }}">Document review</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.recovery-review.index', ['school' => $school->slug]) }}">Recovery requests</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.form-builder.index', ['school' => $school->slug]) }}">Form builder</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.settings.edit', ['school' => $school->slug]) }}">Settings</a>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if(session('error'))
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ session('error') }}</div>
@endif

<div class="stack" style="flex-direction:row;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
  @foreach(['in_progress' => 'In Progress', 'offered' => 'Offered', 'waitlisted' => 'Waitlisted', 'rejected' => 'Rejected', 'enrolled' => 'Enrolled'] as $key => $label)
    <a class="btn btn-sm {{ $tab === $key ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('school.admissions.pipeline', ['school' => $school->slug, 'tab' => $key]) }}">
      {{ $label }} ({{ $counts[$key] }})
    </a>
  @endforeach
</div>

<div class="card">
  @if($applicants->isEmpty())
    <p class="muted">Nothing here.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Applicant</th><th>Class</th><th>Stage</th><th>Assessment</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($applicants as $applicant)
          <tr>
            <td data-label="Applicant">
              <strong>{{ $applicant->name }}</strong>
              <div class="muted">{{ $applicant->admission_number }} &middot; {{ $applicant->phone }}</div>
              @if($applicant->recoverablePassword())
                <div class="muted" style="font-size:.78rem">Password: <span style="font-variant-numeric:tabular-nums">{{ $applicant->recoverablePassword() }}</span></div>
              @endif
            </td>
            <td class="muted" data-label="Class">{{ $applicant->schoolClass?->name ?? 'Not set' }}</td>
            <td class="muted" data-label="Stage">{{ $applicant->currentStageLabel() ?? 'Not started' }}</td>
            <td data-label="Assessment">
              @php($result = $assessmentResults->get($applicant->id))
              @php($scoreLine = $result && $result['score'] !== null ? ' &middot; '.$result['score'].' / '.$result['maxScore'] : '')
              @if(! $result)
                <span class="muted">&mdash;</span>
              @elseif($result['status'] !== 'graded')
                <span class="badge badge-warn">Awaiting grading</span>
              @elseif($result['passed'] === true)
                <span class="badge badge-ok">Passed{{ $scoreLine }}</span>
              @elseif($result['passed'] === false)
                <span class="badge badge-bad">Did not pass{{ $scoreLine }}</span>
              @else
                <span class="badge badge-ok">Graded{{ $scoreLine }}</span>
              @endif
            </td>
            <td data-label="Status">
              <span class="badge {{ $applicant->account_status === 'active' ? 'badge-ok' : ($applicant->account_status === 'archived' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst(str_replace('_', ' ', $applicant->account_status)) }}</span>
              @if($applicant->decision_status)
                <span class="badge {{ $applicant->decision_status === 'rejected' ? 'badge-bad' : 'badge-ok' }}">{{ ucfirst($applicant->decision_status) }}</span>
              @endif
            </td>
            <td style="white-space:nowrap">
              @if($tab === 'in_progress')
                @if(! $applicant->isFinalStage())
                  <form method="POST" action="{{ route('school.admissions.pipeline.advance', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">Advance stage</button>
                  </form>
                @endif
                <form method="POST" action="{{ route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Offer admission</button>
                </form>
                <form method="POST" action="{{ route('school.admissions.pipeline.waitlist', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Waitlist</button>
                </form>
                <form method="POST" action="{{ route('school.admissions.pipeline.reject', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Reject</button>
                </form>
                @if($applicant->email)
                  <form method="POST" action="{{ route('school.admissions.pipeline.nudge', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">Send reminder</button>
                  </form>
                @endif
              @elseif($tab === 'offered')
                <a class="btn btn-primary btn-sm" href="{{ route('school.admissions.pipeline.convert.form', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}">Complete enrollment</a>
                @if($applicant->email)
                  <form method="POST" action="{{ route('school.admissions.pipeline.nudge', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">Send reminder</button>
                  </form>
                @endif
              @elseif($tab === 'waitlisted')
                <form method="POST" action="{{ route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Offer admission</button>
                </form>
                <form method="POST" action="{{ route('school.admissions.pipeline.reject', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Reject</button>
                </form>
              @elseif($tab === 'enrolled' && $applicant->convertedStudent)
                <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $applicant->convertedStudent->id]) }}">View student record</a>
              @else
                <span class="muted">{{ $applicant->decision_notes ?: 'Decided' }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $applicants->links() }}</div>
  @endif
</div>
@endsection
