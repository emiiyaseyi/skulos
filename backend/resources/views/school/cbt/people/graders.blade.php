@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Graders'])
@section('title', 'Graders')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Graders</h1>
    <p class="subtitle">Who marks written answers, at which layer, and whether they mark blind.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.console.index', ['school' => $school->slug, 'tab' => 'graders']) }}">Admin Backend</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a grader</h3>
  <form method="POST" action="{{ route('school.cbt.people.graders.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:.75rem">
      <div class="field">
        <label for="user_id">Member of staff</label>
        <select id="user_id" name="user_id" required>
          <option value="">Choose someone</option>
          @foreach($staff as $person)
            <option value="{{ $person->id }}">{{ $person->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="assessment_id">Scope</label>
        <select id="assessment_id" name="assessment_id">
          <option value="">Every assessment (school wide)</option>
          @foreach($assessments as $assessment)
            <option value="{{ $assessment->id }}">{{ $assessment->title }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="grade_layer">Layer</label>
        <select id="grade_layer" name="grade_layer" required>
          <option value="1">First grader</option>
          <option value="2">Second review (can override the first)</option>
        </select>
      </div>
      <div class="field">
        <label for="deadline">Grading deadline (optional)</label>
        <input id="deadline" name="deadline" type="date" />
      </div>
    </div>
    <label style="display:flex;align-items:flex-start;gap:.5rem;margin:.5rem 0">
      <input type="checkbox" name="anonymous" value="1" style="margin-top:.2rem" />
      <span>Mark blind &mdash; this grader never sees who wrote an answer, only a candidate number</span>
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Add grader</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Current graders</h3>
  @if($graders->isEmpty())
    <p class="muted">Nobody is assigned as a grader yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Name</th><th>Scope</th><th>Layer</th><th>Blind</th><th>Deadline</th><th></th></tr></thead>
      <tbody>
        @foreach($graders as $grader)
          <tr>
            <td data-label="Name">{{ $staffNames[$grader->user_id] ?? 'Unknown' }}</td>
            <td data-label="Scope">
              @if($grader->assessment_id)
                <span class="muted">{{ $grader->assessment?->title }}</span>
              @else
                <span class="badge badge-ok">School wide</span>
              @endif
            </td>
            <td data-label="Layer">{{ $grader->isReviewer() ? 'Second review' : 'First grader' }}</td>
            <td data-label="Blind">
              <form method="POST" action="{{ route('school.cbt.people.graders.anonymous', ['school' => $school->slug, 'graderId' => $grader->id]) }}">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">{{ $grader->anonymous ? 'Blind' : 'Sees names' }}</button>
              </form>
            </td>
            <td class="muted" data-label="Deadline">
              {{ $grader->deadline?->format('d M Y') ?? 'None' }}
              @if($grader->isOverdue())<span class="badge badge-warn">Overdue</span>@endif
            </td>
            <td>
              <form method="POST" action="{{ route('school.cbt.people.graders.destroy', ['school' => $school->slug, 'graderId' => $grader->id]) }}" onsubmit="return confirm('Remove this grader?')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card">
  <h3 style="margin-bottom:.5rem">How work is shared out</h3>
  <p class="muted" style="margin-bottom:.8rem">
    <strong>Everyone</strong> lets every first grader see every attempt.
    <strong>Even split</strong> deals them round robin.
    <strong>Capped random</strong> gives each grader up to a set number.
    <strong>By hand</strong> means you assign each attempt yourself.
    Second reviewers always see everything, whichever mode is chosen.
  </p>
  <div style="overflow-x:auto">
  <table class="table-stack">
    <thead><tr><th>Assessment</th><th>Mode</th><th>Cap</th><th></th></tr></thead>
    <tbody>
      @foreach($assessments as $assessment)
        @php($config = $assessment->gradingConfig)
        <tr>
          <form method="POST" action="{{ route('school.cbt.people.grading-mode', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}" id="gm{{ $assessment->id }}">@csrf</form>
          <td data-label="Assessment"><strong>{{ $assessment->title }}</strong></td>
          <td data-label="Mode">
            <select form="gm{{ $assessment->id }}" name="mode">
              <option value="all" @selected(($config->mode ?? 'all') === 'all')>Everyone</option>
              <option value="split" @selected(($config->mode ?? '') === 'split')>Even split</option>
              <option value="random" @selected(($config->mode ?? '') === 'random')>Capped random</option>
              <option value="manual" @selected(($config->mode ?? '') === 'manual')>By hand</option>
            </select>
          </td>
          <td data-label="Cap"><input form="gm{{ $assessment->id }}" name="cap" type="number" min="0" value="{{ $config->cap ?? 0 }}" style="width:5rem" /></td>
          <td><button form="gm{{ $assessment->id }}" class="btn btn-primary btn-sm" type="submit">Save and share out</button></td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endsection
