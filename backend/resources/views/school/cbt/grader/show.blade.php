@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $takerLabel])
@section('title', 'Marking: '.$takerLabel)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $takerLabel }}</h1>
    <p class="subtitle">
      {{ $assessment->title }} &middot; submitted {{ $session->submitted_at?->format('d M Y H:i') }}
      @if($grader->anonymous) &middot; marking blind @endif
    </p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.grader.queue', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">Back to queue</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

@if($written->isEmpty())
  <div class="card"><p class="muted">This attempt has no written answers to mark.</p></div>
@else
  @foreach($written as $response)
    @php($max = $maxScores[$response->id])
    @php($history = $histories[$response->id])
    <div class="card" style="margin-bottom:1.1rem">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.6rem;margin-bottom:.6rem">
        <div>
          <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em">Question {{ $loop->iteration }} &middot; {{ $max }} mark(s)</div>
          <p style="margin:.3rem 0 0;font-weight:600">{{ $response->question->prompt }}</p>
        </div>
        <span class="badge {{ $response->status === 'ungraded' ? 'badge-warn' : 'badge-ok' }}">
          {{ $response->status === 'ungraded' ? 'Not marked' : 'Marked' }}
        </span>
      </div>

      @if($response->question->marking_guidance)
        <div style="border-left:3px solid var(--accent);padding:.5rem .7rem;margin-bottom:.8rem;background:var(--surface-2,#f6f6f6)">
          <div class="muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Marking guidance</div>
          <div style="font-size:.9rem">{{ $response->question->marking_guidance }}</div>
        </div>
      @endif

      <div style="border:1px solid var(--line);border-radius:8px;padding:.8rem;margin-bottom:.8rem;white-space:pre-wrap">{{ is_string($response->answer) ? $response->answer : 'No answer given.' }}</div>

      <form method="POST" action="{{ route('school.cbt.grader.grade', ['school' => $school->slug, 'responseId' => $response->id]) }}">
        @csrf
        <div class="grid cols-2" style="gap:.75rem">
          <div class="field">
            <label for="score{{ $response->id }}">Mark (out of {{ $max }})</label>
            <input id="score{{ $response->id }}" name="score" type="number" step="0.5" min="0" max="{{ $max }}"
                   value="{{ $response->status === 'ungraded' ? '' : $response->score_awarded }}" required />
          </div>
        </div>
        <div class="field">
          <label for="fb{{ $response->id }}">Feedback (optional)</label>
          <textarea id="fb{{ $response->id }}" name="feedback" rows="2">{{ $response->notes }}</textarea>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Save mark</button>
      </form>

      @if($history->isNotEmpty())
        <details style="margin-top:.8rem">
          <summary class="btn btn-ghost btn-sm" style="cursor:pointer">Grade history ({{ $history->count() }})</summary>
          <div style="margin-top:.6rem">
            @foreach($history as $entry)
              <div style="display:flex;justify-content:space-between;gap:.6rem;border-bottom:1px solid var(--line);padding:.4rem 0;font-size:.85rem">
                <span>{{ $entry->grade_layer === 2 ? 'Second review' : 'First grader' }}</span>
                <span><strong>{{ $entry->score }}</strong> / {{ $max }}</span>
                <span class="muted">{{ $entry->created_at?->format('d M H:i') }}</span>
              </div>
              @if($entry->feedback)
                <div class="muted" style="font-size:.8rem;padding:.2rem 0 .4rem">{{ $entry->feedback }}</div>
              @endif
            @endforeach
            <p class="muted" style="font-size:.78rem;margin-top:.5rem">
              Every mark is kept. A second review overrides the first grader's figure, but never erases it.
            </p>
          </div>
        </details>
      @endif
    </div>
  @endforeach

  @if($grader->isReviewer())
    <div class="card">
      <h3 style="margin-bottom:.5rem">Reviewer actions</h3>
      <p class="muted" style="margin-bottom:.8rem">These are limited to second reviewers.</p>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <form method="POST" action="{{ route('school.cbt.grader.reset', ['school' => $school->slug, 'sessionId' => $session->id]) }}"
              onsubmit="return confirm('Clear every mark on this attempt and return it to ungraded?')">
          @csrf
          <button class="btn btn-ghost btn-sm" type="submit">Reset for re-marking</button>
        </form>
        <form method="POST" action="{{ route('school.cbt.grader.void', ['school' => $school->slug, 'sessionId' => $session->id]) }}"
              onsubmit="return confirm('Void this attempt? It will score zero.')" style="display:flex;gap:.4rem">
          @csrf
          <input name="reason" type="text" placeholder="Reason (optional)" />
          <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Void attempt</button>
        </form>
      </div>
    </div>
  @endif
@endif
@endsection
