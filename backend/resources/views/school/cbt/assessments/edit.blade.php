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
        <label for="timer_mode">Timer</label>
        <select id="timer_mode" name="timer_mode">
          <option value="total" @selected($assessment->timer_mode === 'total')>One clock for the whole assessment</option>
          <option value="per_section" @selected($assessment->timer_mode === 'per_section')>A separate clock per question bank</option>
        </select>
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">Only matters once more than one question bank feeds this assessment. Give each pool below its own minutes to use a separate clock for it.</p>
      </div>
      <div class="field">
        <label for="score_aggregation">Scoring across banks</label>
        <select id="score_aggregation" name="score_aggregation">
          <option value="sum" @selected($assessment->score_aggregation === 'sum')>Add every question's marks together</option>
          <option value="average_sections" @selected($assessment->score_aggregation === 'average_sections')>Mark each bank on its own, then average the banks</option>
        </select>
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">Only matters with more than one question bank. Averaging gives a small bank and a large bank equal weight, reported out of 100, instead of letting the larger bank dominate the total.</p>
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

    <h4 style="margin:1rem 0 .5rem">Access and grading</h4>
    <div class="grid cols-2" style="gap:.75rem">
      <div class="field">
        <label for="opens_at">Opens at (optional)</label>
        <input id="opens_at" name="opens_at" type="datetime-local" value="{{ $assessment->opens_at?->format('Y-m-d\TH:i') }}" />
      </div>
      <div class="field">
        <label for="closes_at">Closes at (optional)</label>
        <input id="closes_at" name="closes_at" type="datetime-local" value="{{ $assessment->closes_at?->format('Y-m-d\TH:i') }}" />
      </div>
      <div class="field">
        <label for="max_attempts">Attempts allowed</label>
        <input id="max_attempts" name="max_attempts" type="number" min="0" max="50" value="{{ $assessment->max_attempts }}" />
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">0 means unlimited.</p>
      </div>
      <div class="field">
        <label for="grade_method">If a taker sits it more than once</label>
        <select id="grade_method" name="grade_method">
          <option value="highest" @selected($assessment->grade_method === 'highest')>Keep the highest score</option>
          <option value="average" @selected($assessment->grade_method === 'average')>Average the scores</option>
          <option value="first" @selected($assessment->grade_method === 'first')>Keep the first attempt</option>
          <option value="last" @selected($assessment->grade_method === 'last')>Keep the last attempt</option>
        </select>
      </div>
      <div class="field">
        <label for="pass_mark">Pass mark (optional)</label>
        <input id="pass_mark" name="pass_mark" type="number" step="0.5" min="0" value="{{ $assessment->pass_mark }}" />
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">
          @if($assessment->score_aggregation === 'average_sections')
            The averaged score is out of 100, so this is a percentage.
          @else
            Compared against the raw marks scored, out of {{ $assessment->totalPoints() ?: 'the assessment\'s total' }}.
          @endif
        </p>
      </div>
      <div class="field">
        <label for="pass_message">Message shown when a taker passes (optional)</label>
        <textarea id="pass_message" name="pass_message" rows="2" maxlength="500" placeholder="e.g. You have successfully moved to the next stage of the application process.">{{ $assessment->pass_message }}</textarea>
      </div>
      <div class="field">
        <label for="fail_message">Message shown when a taker does not pass (optional)</label>
        <textarea id="fail_message" name="fail_message" rows="2" maxlength="500" placeholder="Leave blank for a plain, generic line.">{{ $assessment->fail_message }}</textarea>
      </div>
      <div class="field">
        <label for="access_pin">Access PIN (optional)</label>
        <input id="access_pin" name="access_pin" type="text" maxlength="20" value="{{ $assessment->access_pin }}" />
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">Takers must enter this before starting.</p>
      </div>
      <div class="field">
        <label for="results_mode">When takers see their score</label>
        <select id="results_mode" name="results_mode">
          <option value="immediately" @selected($assessment->results_mode === 'immediately')>Immediately after submitting</option>
          <option value="after_close" @selected($assessment->results_mode === 'after_close')>Once the assessment closes</option>
          <option value="after_days" @selected($assessment->results_mode === 'after_days')>A number of days after submitting</option>
          <option value="manual" @selected($assessment->results_mode === 'manual')>Only when staff release them</option>
          <option value="never" @selected($assessment->results_mode === 'never')>Never</option>
        </select>
      </div>
      <div class="field">
        <label for="results_delay_days">Days to wait (for the option above)</label>
        <input id="results_delay_days" name="results_delay_days" type="number" min="0" max="365" value="{{ $assessment->results_delay_days }}" />
      </div>
      <div class="field">
        <label for="questions_per_page">Questions per page</label>
        <input id="questions_per_page" name="questions_per_page" type="number" min="1" max="100" value="{{ $assessment->questions_per_page }}" />
      </div>
      <div class="field">
        <label for="num_questions">Draw this many questions</label>
        <input id="num_questions" name="num_questions" type="number" min="0" max="500" value="{{ $assessment->num_questions }}" />
        <p class="muted" style="font-size:.78rem;margin-top:.3rem">Only used when a random subset is switched on below. Questions marked "always include" are kept regardless.</p>
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="shuffle_questions" value="1" @checked($assessment->shuffle_questions) /> Shuffle the question order for each taker
    </label>
    <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
      <input type="checkbox" name="randomize" value="1" @checked($assessment->randomize) /> Give each taker a random subset rather than every question
    </label>
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
  <h3 style="margin-bottom:.5rem">Question banks (pools)</h3>
  <p class="muted" style="margin-bottom:.8rem">
    A pool draws a set number of questions at random from one category, its own question bank. Add more than one pool to
    pull from several banks in the same assessment &mdash; each pool becomes its own labelled section, and the taker is
    told which bank they are entering before its questions begin. Pool questions are added on top of any fixed questions
    below. Each taker's own set is frozen when they start, so resuming never re-rolls their paper.
  </p>

  @if($assessment->pools->isNotEmpty())
    <div style="overflow-x:auto;margin-bottom:.8rem">
    <table class="table-stack">
      <thead><tr><th>Section name</th><th>Category</th><th>Draw</th><th>Marks each</th><th>Minutes (per-section timing)</th><th></th></tr></thead>
      <tbody>
        @foreach($assessment->pools as $pool)
          <tr>
            <td data-label="Section name">{{ $pool->label ?: ($pool->category?->name ?? 'Removed category') }}</td>
            <td class="muted" data-label="Category">{{ $pool->category?->name ?? 'Removed category' }}</td>
            <td class="muted" data-label="Draw">{{ $pool->draw_count }}</td>
            <td class="muted" data-label="Marks each">{{ $pool->points_each }}</td>
            <td class="muted" data-label="Minutes">{{ $pool->duration_minutes ?? 'Shares the total clock' }}</td>
            <td>
              <form method="POST" action="{{ route('school.cbt.assessments.pools.destroy', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'poolId' => $pool->id]) }}" onsubmit="return confirm('Remove this pool?')">
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

  <form method="POST" action="{{ route('school.cbt.assessments.pools.store', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
    @csrf
    <div class="field" style="margin:0;flex:1;min-width:10rem">
      <label for="pool_label">Section name (optional)</label>
      <input id="pool_label" name="label" type="text" maxlength="100" placeholder="Uses the category name if left blank" />
    </div>
    <div class="field" style="margin:0;flex:1;min-width:12rem">
      <label for="pool_category">Category</label>
      <select id="pool_category" name="category_id" required>
        <option value="">Choose a category</option>
        @foreach($categories as $category)
          <option value="{{ $category->id }}">{{ $category->name }} ({{ $category->questions_count }} active)</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="pool_draw">Questions per learner</label>
      <input id="pool_draw" name="draw_count" type="number" min="1" value="5" style="width:5rem" required />
      <p class="muted" style="font-size:.72rem;margin-top:.2rem">e.g. 20 random questions from 50 in the bank</p>
    </div>
    <div class="field" style="margin:0">
      <label for="pool_points">Marks each</label>
      <input id="pool_points" name="points_each" type="number" step="0.5" min="0.5" value="1" style="width:6rem" required />
    </div>
    <div class="field" style="margin:0">
      <label for="pool_minutes">Minutes (optional)</label>
      <input id="pool_minutes" name="duration_minutes" type="number" min="1" style="width:6rem" placeholder="Total" />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add pool</button>
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
