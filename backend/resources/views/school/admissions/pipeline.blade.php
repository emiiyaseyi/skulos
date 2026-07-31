@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Applicants'])
@section('title', 'Applicants')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Applicants</h1>
    <p class="subtitle">Every applicant currently in the admission pipeline, by status.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.fees.index', ['school' => $school->slug]) }}">Fee payments</a>
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
      <thead><tr><th>Applicant</th><th>Class</th><th>Stage</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($applicants as $applicant)
          <tr>
            <td data-label="Applicant"><strong>{{ $applicant->name }}</strong><div class="muted">{{ $applicant->admission_number }} &middot; {{ $applicant->phone }}</div></td>
            <td class="muted" data-label="Class">{{ $applicant->schoolClass?->name ?? 'Not set' }}</td>
            <td class="muted" data-label="Stage">{{ $stageCatalog[$applicant->currentStageKey()] ?? 'Not started' }}</td>
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
