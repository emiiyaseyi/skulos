@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $assessment->title])
@section('title', $assessment->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $assessment->title }}</h1>
    <p class="subtitle">{{ $assessment->assessmentQuestions->count() }} question(s) &middot; {{ $assessment->totalPoints() }} points &middot; {{ $assessment->duration_minutes }} minutes</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.assessments.index', ['school' => $school->slug]) }}">All assessments</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Assessment settings</h3>
  <form method="POST" action="{{ route('school.cbt.assessments.update', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">
    @csrf
    <div class="grid cols-2" style="gap:.75rem">
      <div class="field">
        <label for="title">Title</label>
        <input id="title" name="title" type="text" value="{{ $assessment->title }}" required />
      </div>
      <div class="field">
        <label for="duration_minutes">Duration (minutes)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="1" value="{{ $assessment->duration_minutes }}" required />
      </div>
      <div class="field">
        <label for="subject_id">Subject (optional)</label>
        <select id="subject_id" name="subject_id">
          <option value="">Not set</option>
          @foreach($subjects as $subject)
            <option value="{{ $subject->id }}" @selected($assessment->subject_id === $subject->id)>{{ $subject->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="term_id">Term (optional)</label>
        <select id="term_id" name="term_id">
          <option value="">Not set</option>
          @foreach($terms as $term)
            <option value="{{ $term->id }}" @selected($assessment->term_id === $term->id)>{{ $term->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="security_profile_id">Security profile</label>
        <select id="security_profile_id" name="security_profile_id" required>
          @foreach($securityProfiles as $profile)
            <option value="{{ $profile->id }}" @selected($assessment->security_profile_id === $profile->id)>{{ $profile->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="audience">Who takes this</label>
        <select id="audience" name="audience" required>
          <option value="student" @selected($assessment->audience === 'student')>Students</option>
          <option value="staff" @selected($assessment->audience === 'staff')>Staff</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="instructions">Instructions (optional)</label>
      <textarea id="instructions" name="instructions" rows="2">{{ $assessment->instructions }}</textarea>
    </div>

    <div class="field">
      <label>Class arms who may attempt this (optional)</label>
      <p class="muted" style="font-size:.8rem;margin-bottom:.4rem">Leave every box unticked to offer this to the whole {{ $assessment->audience }} audience, matching the default behaviour. Tick specific arms to restrict it to those classes' own enrolled students — combined with anyone individually assigned via Bulk Assignment.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));gap:.4rem;max-height:12rem;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:.6rem">
        @foreach($classArms as $arm)
          <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem">
            <input type="checkbox" name="class_arm_ids[]" value="{{ $arm->id }}" @checked($assessment->classArms->contains('id', $arm->id)) />
            {{ $arm->schoolClass?->name }} {{ $arm->arm?->name }}
          </label>
        @endforeach
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="requires_double_grading" value="1" @checked($assessment->requires_double_grading) /> Require two independent graders for subjective answers
    </label>
    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="is_active" value="1" @checked($assessment->is_active) /> Active
    </label>
    <button class="btn btn-primary btn-sm" type="submit">Save assessment settings</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.8rem">Add a question from the bank</h3>
  @if($bankQuestions->isEmpty())
    <p class="muted">No more active questions available to add. <a href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">Build more in the question bank</a>.</p>
  @else
    <form method="POST" action="{{ route('school.cbt.assessments.questions.store', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}" style="display:flex;gap:.5rem">
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
  <h3 style="margin-bottom:.8rem">Questions on this assessment</h3>
  @if($assessment->assessmentQuestions->isEmpty())
    <p class="muted">No questions added yet.</p>
  @else
    <div class="stack" style="gap:.5rem">
      @foreach($assessment->assessmentQuestions as $aq)
        <div style="display:flex;align-items:center;gap:.6rem;border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem">
          <span class="muted" style="font-weight:700;min-width:1.4rem">{{ $loop->index + 1 }}.</span>
          <div style="flex:1">
            <div>{{ \Illuminate\Support\Str::limit($aq->question->prompt, 90) }}</div>
            <div class="muted" style="font-size:.78rem">{{ ucfirst(str_replace('_', ' ', $aq->question->type)) }} &middot; {{ $aq->points_override ?? $aq->question->points }} points</div>
          </div>
          <form method="POST" action="{{ route('school.cbt.assessments.questions.move', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'assessmentQuestionId' => $aq->id, 'direction' => 'up']) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit" @disabled($loop->first)>&uarr;</button>
          </form>
          <form method="POST" action="{{ route('school.cbt.assessments.questions.move', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'assessmentQuestionId' => $aq->id, 'direction' => 'down']) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit" @disabled($loop->last)>&darr;</button>
          </form>
          <form method="POST" action="{{ route('school.cbt.assessments.questions.destroy', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'assessmentQuestionId' => $aq->id]) }}">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
          </form>
        </div>
      @endforeach
    </div>
  @endif
</div>
@endsection
