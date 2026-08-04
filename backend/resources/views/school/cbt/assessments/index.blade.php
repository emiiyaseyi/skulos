@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessments'])
@section('title', 'Assessments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessments</h1>
    <p class="subtitle">Timed assessments assembled from your question bank.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">Question bank</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.security-profiles.index', ['school' => $school->slug]) }}">Security profiles</a>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">New assessment</h3>
  <form method="POST" action="{{ route('school.cbt.assessments.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:.75rem">
      <div class="field">
        <label for="title">Title</label>
        <input id="title" name="title" type="text" required />
      </div>
      <div class="field">
        <label for="duration_minutes">Duration (minutes)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="1" required />
      </div>
      <div class="field">
        <label for="security_profile_id">Security profile</label>
        <select id="security_profile_id" name="security_profile_id" required>
          @foreach($securityProfiles as $profile)
            <option value="{{ $profile->id }}">{{ $profile->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="audience">Who takes this</label>
        <select id="audience" name="audience" required>
          <option value="student">Students (class assessment)</option>
          <option value="staff">Staff (teacher assessment)</option>
          <option value="applicant">Applicants (admission assessment)</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="instructions">Instructions (optional)</label>
      <textarea id="instructions" name="instructions" rows="2"></textarea>
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="requires_double_grading" value="1" /> Require two independent graders for subjective answers
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Create and add questions</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Assessments</h3>
  @if($assessments->isEmpty())
    <p class="muted">No assessments yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Title</th><th>Subject</th><th>Audience</th><th>Duration</th><th>Security</th><th>Questions</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($assessments as $assessment)
          <tr>
            <td data-label="Title"><strong>{{ $assessment->title }}</strong></td>
            <td class="muted" data-label="Subject">{{ $assessment->subject?->name ?? 'Not set' }}</td>
            <td class="muted" data-label="Audience">{{ ucfirst($assessment->audience) }}</td>
            <td class="muted" data-label="Duration">{{ $assessment->duration_minutes }} min</td>
            <td class="muted" data-label="Security">{{ $assessment->securityProfile?->name }}</td>
            <td class="muted" data-label="Questions">{{ $assessment->assessment_questions_count }}</td>
            <td data-label="Status"><span class="badge {{ $assessment->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $assessment->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td style="white-space:nowrap">
              <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.hub.index', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">Manage</a>
              <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">Edit</a>
              <form method="POST" action="{{ route('school.cbt.assessments.destroy', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}" style="display:inline" onsubmit="return confirm('Remove this assessment?')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $assessments->links() }}</div>
  @endif
</div>
@endsection
