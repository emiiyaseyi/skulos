@extends('layouts.app', ['portal' => $portal ?? 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessments'])
@section('title', 'Assessments')
{{-- See result.blade.php's own comment: the admissions portal has no
     nav-admissions partial, every applicant-facing view goes bare. --}}
@if(($portal ?? 'school') === 'admissions')
  @section('bare', true)
@endif

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessments</h1>
    <p class="subtitle">Timed assessments assigned to you.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

@if($rows->isEmpty())
  <div class="card">
    <p class="muted">Nothing assigned right now.</p>
  </div>
@else
  <div class="stack" style="gap:1rem">
    @foreach($rows as $row)
      @php($assessment = $row['assessment'])
      @php($session = $row['session'])
      @php($fixedCount = $assessment->assessmentQuestions->count())
      <div class="card" style="max-width:38rem;margin:0 auto;width:100%;text-align:center">
        <h2 style="margin-bottom:.3rem">{{ $assessment->displayName() }}</h2>
        <div style="display:flex;justify-content:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
          <span class="muted" style="font-size:.85rem">{{ $assessment->duration_minutes }} minutes total</span>
          @if(! $session)
            <span class="badge badge-warn">Not started</span>
          @elseif($session->status === 'in_progress')
            <span class="badge badge-warn">In progress</span>
          @elseif($session->status === 'graded' && $row['scoreVisible'])
            <span class="badge badge-ok">Graded &middot; {{ $session->total_score }}/{{ $session->max_score }}</span>
          @elseif($session->status === 'graded')
            <span class="badge badge-ok">Graded &middot; result not released yet</span>
          @else
            <span class="badge badge-ok">Submitted, awaiting grading</span>
          @endif
        </div>

        @if($assessment->instructions)
          <p style="text-align:justify;margin-bottom:1.25rem">{{ $assessment->instructions }}</p>
        @endif

        @if($assessment->pools->isNotEmpty() || $fixedCount)
          <div style="text-align:left;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.9rem 1rem;margin-bottom:1.25rem">
            <div style="font-weight:700;font-size:.85rem;margin-bottom:.5rem">What this assessment covers</div>
            <ul style="margin:0;padding-left:1.1rem;font-size:.88rem;line-height:1.6">
              @if($fixedCount)
                <li>General questions &middot; {{ $fixedCount }} question{{ $fixedCount === 1 ? '' : 's' }}</li>
              @endif
              @foreach($assessment->pools as $pool)
                <li>
                  {{ $pool->label ?: ($pool->category?->name ?? 'Question bank') }}
                  &middot; {{ $pool->draw_count }} question{{ $pool->draw_count === 1 ? '' : 's' }}
                </li>
              @endforeach
            </ul>
          </div>
        @endif

        <div style="text-align:left;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.9rem 1rem;margin-bottom:1.25rem">
          <div style="font-weight:700;font-size:.85rem;margin-bottom:.5rem">Before you begin</div>
          <ul style="margin:0;padding-left:1.1rem;font-size:.88rem;line-height:1.6">
            <li>Make sure you have a stable internet connection for the full {{ $assessment->duration_minutes }} minutes.</li>
            <li>The timer starts the moment you click Start and keeps running even if you close the page.</li>
            <li>You can move between questions freely and change your answers before submitting.</li>
            @if((int) $assessment->max_attempts === 1)
              <li>You only get one attempt, so make sure you are ready before you begin.</li>
            @elseif((int) $assessment->max_attempts > 1)
              <li>You are allowed up to {{ $assessment->max_attempts }} attempts.</li>
            @endif
          </ul>
        </div>

        <div>
          @if(! $session)
            <form method="POST" action="{{ $row['startUrl'] }}" style="display:inline">
              @csrf
              <button class="btn btn-primary" type="submit">Start</button>
            </form>
          @elseif($session->status === 'in_progress')
            <a class="btn btn-primary" href="{{ $row['resumeUrl'] }}">Resume</a>
          @else
            <a class="btn btn-ghost" href="{{ $row['resultUrl'] }}">View result</a>
          @endif
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection
