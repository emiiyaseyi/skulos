@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessment Papers'])
@section('title', 'Assessment Papers')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessment Papers</h1>
    <p class="subtitle">Timed papers assembled from your question bank.</p>
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
  <h3 style="margin-bottom:.8rem">New paper</h3>
  <form method="POST" action="{{ route('school.cbt.papers.store', ['school' => $school->slug]) }}">
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
          <option value="student">Students</option>
          <option value="staff">Staff</option>
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
  <h3 style="margin-bottom:.8rem">Papers</h3>
  @if($papers->isEmpty())
    <p class="muted">No papers yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Title</th><th>Audience</th><th>Duration</th><th>Security</th><th>Questions</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($papers as $paper)
          <tr>
            <td data-label="Title"><strong>{{ $paper->title }}</strong></td>
            <td class="muted" data-label="Audience">{{ ucfirst($paper->audience) }}</td>
            <td class="muted" data-label="Duration">{{ $paper->duration_minutes }} min</td>
            <td class="muted" data-label="Security">{{ $paper->securityProfile?->name }}</td>
            <td class="muted" data-label="Questions">{{ $paper->paper_questions_count }}</td>
            <td data-label="Status"><span class="badge {{ $paper->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $paper->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.papers.edit', ['school' => $school->slug, 'paperId' => $paper->id]) }}">Edit</a>
              <form method="POST" action="{{ route('school.cbt.papers.destroy', ['school' => $school->slug, 'paperId' => $paper->id]) }}" style="display:inline" onsubmit="return confirm('Remove this paper?')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $papers->links() }}</div>
  @endif
</div>
@endsection
