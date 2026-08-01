@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $assessment->displayName()])
@section('title', $assessment->displayName())
@section('bare', true)

@section('content')
@php
  $secProfile = $session->assessment->securityProfile;
  $proctoring = \App\Support\AssessmentMode::proctoringEnabled();
  $brand = $assessment->brandColor($accent) ?: '#0f6cbf';

  // Answered-so-far, for the header progress line and the navigator.
  $answeredIds = collect($existingAnswers)
    ->filter(fn ($a) => $a !== null && $a !== '' && $a !== [])
    ->keys()->map(fn ($id) => (int) $id)->all();

  $profileForJs = [
    'require_fullscreen' => $proctoring && $secProfile->require_fullscreen,
    'block_copy_paste' => $proctoring && $secProfile->block_copy_paste,
    'block_right_click' => $proctoring && $secProfile->block_right_click,
    'tab_switch_action' => $proctoring ? $secProfile->tab_switch_action : 'none',
    'require_camera_check' => $proctoring && $secProfile->require_camera_check,
    'require_object_detection' => $proctoring && $secProfile->require_object_detection,
    'snapshot_interval_seconds' => $secProfile->snapshot_interval_seconds,
  ];
@endphp

<div id="lgShell" style="--exam-brand:{{ $brand }}">
  {{-- Top bar: title, live progress, and the countdown --}}
  <header class="lg-topbar">
    <div class="lg-topbar-left">
      <div class="lg-title">{{ $assessment->displayName() }}</div>
      <div class="lg-sub">{{ $school->name }}</div>
    </div>
    <div class="lg-topbar-mid">
      <div id="lgProgressText" class="lg-progress-text">Q 1 of {{ $given->count() }}</div>
      <div class="lg-progress-track"><div id="lgProgressBar" class="lg-progress-bar"></div></div>
    </div>
    <div class="lg-topbar-right">
      <div id="cbtTimer" class="lg-timer" data-ends-at="{{ $session->ends_at->toIso8601String() }}">--:--</div>
    </div>
  </header>

  <div class="lg-body">
    {{-- One question per page --}}
    <main id="lgQuestionScroll" class="lg-question-scroll" role="main" aria-label="Exam question">
      @if($assessment->instructions)
        <div class="lg-instructions" id="lgInstructions">{{ $assessment->instructions }}</div>
      @endif

      @foreach($given as $pq)
        @php
          $question = $pq->question;
          $current = $existingAnswers[$question->id] ?? null;
          $typeLabel = strtoupper(str_replace('_', ' ', $question->type));
          $entersNewSection = $showSections && $pq->section_label !== null
            && ($loop->first || $pq->section_label !== $given[$loop->index - 1]->section_label);
        @endphp
        @if($entersNewSection)
          {{-- Announced before its questions begin: the taker is told
               which question bank a section came from, so a paper
               drawing from several banks reads as clearly labelled
               parts rather than one undifferentiated list. --}}
          <section class="lg-question lg-section-banner" data-index="{{ $loop->index }}"
                   data-section-banner="{{ $pq->section_order }}" hidden>
            <div class="lg-section-banner-card">
              <div class="lg-section-eyebrow">Question bank</div>
              <h2>{{ $pq->section_label }}</h2>
              <p class="muted">{{ $given->where('section_label', $pq->section_label)->count() }} question(s){{ $perSectionTimer ? ' &middot; timed on its own' : '' }}</p>
              <button type="button" class="lg-btn lg-btn-primary" data-enter-section>Begin this section &rarr;</button>
            </div>
          </section>
        @endif
        <section class="lg-question cbt-question" data-index="{{ $loop->index }}"
                 data-question-id="{{ $question->id }}" data-type="{{ $question->type }}"
                 data-section-order="{{ $pq->section_order }}"
                 @if(! $loop->first || $entersNewSection) hidden @endif>
          <div class="lg-qhead">
            <span class="lg-qnum">{{ $loop->index + 1 }}</span>
            <span class="lg-qtype">{{ $typeLabel }}</span>
            <span class="lg-qmarks">{{ $pq->points }} {{ (float) $pq->points === 1.0 ? 'mark' : 'marks' }}</span>
          </div>

          <div class="lg-qbody">
            <p class="lg-qtext">{{ $question->prompt }}</p>

            @if($question->image_path)
              <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($question->image_path) }}"
                   alt="" class="lg-qimage" />
            @endif

            @if($question->type === 'multiple_choice_single' || $question->type === 'true_false')
              @foreach($question->answers as $option)
                <label class="lg-option">
                  <input type="radio" name="q{{ $question->id }}" value="{{ $option->id }}"
                         @checked((string) $current === (string) $option->id) />
                  <span class="lg-option-letter">{{ chr(65 + $loop->index) }}</span>
                  <span class="lg-option-text">{{ $option->answer_text }}</span>
                </label>
              @endforeach

            @elseif($question->type === 'multiple_choice_multiple')
              <p class="lg-hint">Select all that apply.</p>
              @foreach($question->answers as $option)
                <label class="lg-option">
                  <input type="checkbox" name="q{{ $question->id }}" value="{{ $option->id }}"
                         @checked(is_array($current) && in_array((string) $option->id, array_map('strval', $current), true)) />
                  <span class="lg-option-letter">{{ chr(65 + $loop->index) }}</span>
                  <span class="lg-option-text">{{ $option->answer_text }}</span>
                </label>
              @endforeach

            @elseif($question->type === 'short_answer')
              <input type="text" class="lg-input" value="{{ $current }}" placeholder="Type your answer here" />

            @elseif($question->type === 'essay')
              <textarea rows="9" class="lg-textarea" placeholder="Write your response here">{{ $current }}</textarea>
              <div class="lg-essay-note">This response will be read and marked by a person, not automatically.</div>

            @elseif($question->type === 'matching')
              @php($rightOptions = $question->answers->pluck('feedback')->filter()->unique()->values())
              @foreach($question->answers as $stem)
                <div class="lg-match-row">
                  <span class="lg-match-stem">{{ $stem->answer_text }}</span>
                  <span class="lg-match-arrow">&rarr;</span>
                  <select data-left-id="{{ $stem->id }}" class="lg-select">
                    <option value="">Choose a match</option>
                    @foreach($rightOptions as $rightItem)
                      <option value="{{ $rightItem }}" @selected(($current[$stem->id] ?? null) === $rightItem)>{{ $rightItem }}</option>
                    @endforeach
                  </select>
                </div>
              @endforeach
            @endif

            <div class="lg-savestate" data-save-status></div>
          </div>
        </section>
      @endforeach
    </main>

    {{-- Floating side toolbar --}}
    <aside class="lg-toolbar" aria-label="Exam tools">
      <div class="lg-tool-card">
        <div class="lg-tool-head">{{ $assessment->displayName() }}</div>
        <div class="lg-tool-rows">
          <div><span>Answered</span><strong id="lgAnswered">0</strong></div>
          <div><span>Remaining</span><strong id="lgRemaining">{{ $given->count() }}</strong></div>
          <div><span>Flagged</span><strong id="lgFlagged">0</strong></div>
        </div>
      </div>

      <button type="button" class="lg-tool-btn" id="lgCalcBtn">Calculator</button>
      <button type="button" class="lg-tool-btn" id="lgNotesBtn">Notes</button>
      <div class="lg-zoom">
        <button type="button" id="lgZoomOut" aria-label="Smaller text">A&minus;</button>
        <span id="lgZoomLabel">100%</span>
        <button type="button" id="lgZoomIn" aria-label="Larger text">A+</button>
      </div>
      <button type="button" class="lg-tool-btn lg-tool-break" id="requestBreakBtn">Request a break</button>
      <div id="breakStatus" class="lg-tool-note"></div>
    </aside>
  </div>

  {{-- Question navigator --}}
  <div id="lgNavPanel" class="lg-navpanel" hidden>
    <div class="lg-navpanel-head">
      <span>Question navigator</span>
      <span class="lg-legend">
        <span><i class="lg-dot lg-dot-answered"></i> Answered</span>
        <span><i class="lg-dot lg-dot-todo"></i> Not yet</span>
        <span><i class="lg-dot lg-dot-flagged"></i> Flagged</span>
      </span>
    </div>
    <div id="lgNavGrid" class="lg-navgrid"></div>
  </div>

  {{-- Bottom bar --}}
  <footer class="lg-bottombar">
    <button type="button" class="lg-btn lg-btn-ghost" id="lgPrev">&larr; Previous</button>
    <button type="button" class="lg-btn lg-btn-ghost" id="lgFlag">Flag for review</button>
    <button type="button" class="lg-btn lg-btn-nav" id="lgNavToggle">Navigator</button>
    <button type="button" class="lg-btn lg-btn-primary" id="lgNext">Save &amp; next &rarr;</button>
    <button type="button" class="lg-btn lg-btn-submit" id="lgSubmit" hidden>Submit assessment</button>
  </footer>

  {{-- Scratch pad --}}
  <div id="lgNotes" class="lg-panel" hidden>
    <div class="lg-panel-head">Scratch pad<button type="button" class="lg-panel-close" data-close="lgNotes">&times;</button></div>
    <textarea id="lgNotesText" class="lg-panel-body" placeholder="Write notes here. Only you can see them, and they are not submitted." spellcheck="false"></textarea>
  </div>

  {{-- Calculator --}}
  <div id="lgCalc" class="lg-panel lg-panel-calc" hidden>
    <div class="lg-panel-head">Calculator<button type="button" class="lg-panel-close" data-close="lgCalc">&times;</button></div>
    <input id="lgCalcDisplay" class="lg-calc-display" readonly value="0" />
    <div class="lg-calc-keys" id="lgCalcKeys">
      @foreach(['C','±','%','÷','7','8','9','×','4','5','6','−','1','2','3','+','0','.','⌫','='] as $key)
        <button type="button" data-key="{{ $key }}">{{ $key }}</button>
      @endforeach
    </div>
  </div>

  @if($session->isOnBreak())
    <div id="breakOverlay" class="lg-overlay">
      <h2>You are on an approved break</h2>
      <p id="breakCountdown" data-ends-at="{{ $session->break_ends_at->toIso8601String() }}">Resuming shortly...</p>
      <p class="lg-overlay-note">Your exam timer is paused.</p>
    </div>
  @endif

  {{-- Submit confirmation --}}
  <div id="lgConfirm" class="lg-overlay" hidden>
    <div class="lg-confirm-card">
      <h2>Submit your assessment?</h2>
      <p id="lgConfirmBody">You cannot change your answers after this.</p>
      <div class="lg-confirm-actions">
        <button type="button" class="lg-btn lg-btn-ghost" id="lgConfirmCancel">Cancel</button>
        <button type="button" class="lg-btn lg-btn-submit" id="lgConfirmGo">Submit</button>
      </div>
    </div>
  </div>

  <form id="cbtSubmitForm" method="POST"
        action="{{ route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]) }}">
    @csrf
  </form>
</div>

@include('school.cbt.take._exam-styles')

@if($proctoring && $secProfile->require_camera_check)
  <script src="{{ asset('vendor/proctoring/face-api.min.js') }}"></script>
@endif
@if($proctoring && $secProfile->require_object_detection)
  <script src="{{ asset('vendor/proctoring/onnx/ort.min.js') }}"></script>
@endif

@include('school.cbt.take._exam-scripts', [
  'profileForJs' => $profileForJs,
  'answeredIds' => $answeredIds,
  'proctoring' => $proctoring,
  'sectionMeta' => $sectionMeta,
  'showSections' => $showSections,
  'perSectionTimer' => $perSectionTimer,
])
@endsection
