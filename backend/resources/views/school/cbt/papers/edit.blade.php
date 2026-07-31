@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $paper->title])
@section('title', $paper->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $paper->title }}</h1>
    <p class="subtitle">{{ $paper->paperQuestions->count() }} question(s) &middot; {{ $paper->totalPoints() }} points &middot; {{ $paper->duration_minutes }} minutes</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.papers.index', ['school' => $school->slug]) }}">All papers</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Paper settings</h3>
  <form method="POST" action="{{ route('school.cbt.papers.update', ['school' => $school->slug, 'paperId' => $paper->id]) }}">
    @csrf
    <div class="grid cols-2" style="gap:.75rem">
      <div class="field">
        <label for="title">Title</label>
        <input id="title" name="title" type="text" value="{{ $paper->title }}" required />
      </div>
      <div class="field">
        <label for="duration_minutes">Duration (minutes)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="1" value="{{ $paper->duration_minutes }}" required />
      </div>
      <div class="field">
        <label for="security_profile_id">Security profile</label>
        <select id="security_profile_id" name="security_profile_id" required>
          @foreach($securityProfiles as $profile)
            <option value="{{ $profile->id }}" @selected($paper->security_profile_id === $profile->id)>{{ $profile->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="audience">Who takes this</label>
        <select id="audience" name="audience" required>
          <option value="student" @selected($paper->audience === 'student')>Students</option>
          <option value="staff" @selected($paper->audience === 'staff')>Staff</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="instructions">Instructions (optional)</label>
      <textarea id="instructions" name="instructions" rows="2">{{ $paper->instructions }}</textarea>
    </div>
    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="requires_double_grading" value="1" @checked($paper->requires_double_grading) /> Require two independent graders for subjective answers
    </label>
    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="is_active" value="1" @checked($paper->is_active) /> Active
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Save paper settings</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a question from the bank</h3>
  @if($bankQuestions->isEmpty())
    <p class="muted">No more active questions available to add. <a href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">Build more in the question bank</a>.</p>
  @else
    <form method="POST" action="{{ route('school.cbt.papers.questions.store', ['school' => $school->slug, 'paperId' => $paper->id]) }}" style="display:flex;gap:.5rem">
      @csrf
      <select name="question_id" required style="flex:1">
        <option value="">Choose a question</option>
        @foreach($bankQuestions as $bq)
          <option value="{{ $bq->id }}">{{ \Illuminate\Support\Str::limit($bq->prompt, 70) }} ({{ ucfirst(str_replace('_', ' ', $bq->type)) }})</option>
        @endforeach
      </select>
      <button class="btn btn-primary btn-sm" type="submit">Add</button>
    </form>
  @endif
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Questions on this paper</h3>
  @if($paper->paperQuestions->isEmpty())
    <p class="muted">No questions added yet.</p>
  @else
    <div class="stack" style="gap:.5rem">
      @foreach($paper->paperQuestions as $pq)
        <div style="display:flex;align-items:center;gap:.6rem;border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem">
          <span class="muted" style="font-weight:700;min-width:1.4rem">{{ $loop->index + 1 }}.</span>
          <div style="flex:1">
            <div>{{ \Illuminate\Support\Str::limit($pq->question->prompt, 90) }}</div>
            <div class="muted" style="font-size:.78rem">{{ ucfirst(str_replace('_', ' ', $pq->question->type)) }} &middot; {{ $pq->points_override ?? $pq->question->points }} points</div>
          </div>
          <form method="POST" action="{{ route('school.cbt.papers.questions.move', ['school' => $school->slug, 'paperId' => $paper->id, 'paperQuestionId' => $pq->id, 'direction' => 'up']) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit" @disabled($loop->first)>&uarr;</button>
          </form>
          <form method="POST" action="{{ route('school.cbt.papers.questions.move', ['school' => $school->slug, 'paperId' => $paper->id, 'paperQuestionId' => $pq->id, 'direction' => 'down']) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit" @disabled($loop->last)>&darr;</button>
          </form>
          <form method="POST" action="{{ route('school.cbt.papers.questions.destroy', ['school' => $school->slug, 'paperId' => $paper->id, 'paperQuestionId' => $pq->id]) }}">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
          </form>
        </div>
      @endforeach
    </div>
  @endif
</div>
@endsection
