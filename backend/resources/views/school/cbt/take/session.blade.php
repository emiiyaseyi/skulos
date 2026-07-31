@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $paper->title])
@section('title', $paper->title)
@section('bare', true)

@section('content')
<div style="max-width:40rem;margin:1.5rem auto;padding:0 1.25rem">
  <div class="page-head" style="position:sticky;top:0;background:var(--bg);z-index:5;padding-bottom:.6rem">
    <div>
      <h1 class="title">{{ $paper->title }}</h1>
      <p class="subtitle" id="cbtTimer" data-ends-at="{{ $session->ends_at->toIso8601String() }}">Loading time remaining...</p>
    </div>
  </div>

  @if($paper->instructions)
    <div class="card" style="margin-bottom:1rem"><p class="muted">{{ $paper->instructions }}</p></div>
  @endif

  <form id="cbtSubmitForm" method="POST" action="{{ route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]) }}" onsubmit="return confirm('Submit your answers? You cannot change them after this.')">
    @csrf
  </form>

  @foreach($paper->paperQuestions as $pq)
    @php($question = $pq->question)
    @php($current = $existingAnswers[$question->id] ?? null)
    <div class="card cbt-question" data-question-id="{{ $question->id }}" data-type="{{ $question->type }}" style="margin-bottom:1rem">
      <div class="muted" style="font-size:.8rem;margin-bottom:.4rem">Question {{ $loop->index + 1 }} &middot; {{ $pq->points_override ?? $question->points }} point(s)</div>
      <p style="margin-bottom:.8rem">{{ $question->prompt }}</p>
      @if($question->image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($question->image_path) }}" alt="" style="max-width:100%;border-radius:8px;margin-bottom:.8rem" />
      @endif

      @if($question->type === 'multiple_choice_single')
        @foreach($question->options ?? [] as $option)
          <label style="display:block;margin-bottom:.4rem">
            <input type="radio" name="q{{ $question->id }}" value="{{ $option }}" @checked($current === $option) /> {{ $option }}
          </label>
        @endforeach
      @elseif($question->type === 'multiple_choice_multiple')
        @foreach($question->options ?? [] as $option)
          <label style="display:block;margin-bottom:.4rem">
            <input type="checkbox" name="q{{ $question->id }}" value="{{ $option }}" @checked(is_array($current) && in_array($option, $current, true)) /> {{ $option }}
          </label>
        @endforeach
      @elseif($question->type === 'true_false')
        <label style="display:block;margin-bottom:.4rem"><input type="radio" name="q{{ $question->id }}" value="true" @checked($current === true) /> True</label>
        <label style="display:block;margin-bottom:.4rem"><input type="radio" name="q{{ $question->id }}" value="false" @checked($current === false) /> False</label>
      @elseif($question->type === 'short_answer')
        <input type="text" value="{{ $current }}" />
      @elseif($question->type === 'essay')
        <textarea rows="5">{{ $current }}</textarea>
      @elseif($question->type === 'matching')
        @foreach($question->options['left'] ?? [] as $i => $leftItem)
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
            <span style="flex:1">{{ $leftItem }}</span>
            <select data-left-index="{{ $i }}">
              <option value="">Choose a match</option>
              @foreach($question->options['right'] ?? [] as $j => $rightItem)
                <option value="{{ $j }}" @selected(($current[$i] ?? null) == $j)>{{ $rightItem }}</option>
              @endforeach
            </select>
          </div>
        @endforeach
      @endif
      <div class="muted" style="font-size:.75rem;margin-top:.4rem" data-save-status>Saved</div>
    </div>
  @endforeach

  <button class="btn btn-primary btn-block" type="submit" form="cbtSubmitForm">Submit assessment</button>
</div>

@push('scripts')
<script>
(function () {
  var sessionId = {{ $session->id }};
  var saveUrl = '{{ route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]) }}';
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

  function getAnswer(block) {
    var type = block.dataset.type;
    if (type === 'multiple_choice_single' || type === 'true_false') {
      var checked = block.querySelector('input[type=radio]:checked');
      if (!checked) return null;
      return type === 'true_false' ? (checked.value === 'true') : checked.value;
    }
    if (type === 'multiple_choice_multiple') {
      return Array.prototype.map.call(block.querySelectorAll('input[type=checkbox]:checked'), function (cb) { return cb.value; });
    }
    if (type === 'short_answer') {
      var input = block.querySelector('input[type=text]');
      return input ? input.value : null;
    }
    if (type === 'essay') {
      var ta = block.querySelector('textarea');
      return ta ? ta.value : null;
    }
    if (type === 'matching') {
      var answer = {};
      Array.prototype.forEach.call(block.querySelectorAll('select'), function (sel) {
        if (sel.value !== '') answer[sel.dataset.leftIndex] = sel.value;
      });
      return answer;
    }
    return null;
  }

  function saveBlock(block) {
    var status = block.querySelector('[data-save-status]');
    if (status) status.textContent = 'Saving...';
    fetch(saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
      body: JSON.stringify({ question_id: block.dataset.questionId, answer: getAnswer(block) }),
    }).then(function (r) {
      if (status) status.textContent = r.ok ? 'Saved' : 'Not saved — check your connection';
    }).catch(function () {
      if (status) status.textContent = 'Not saved — check your connection';
    });
  }

  var debounceTimers = {};
  document.querySelectorAll('.cbt-question').forEach(function (block) {
    block.addEventListener('input', function () {
      var qid = block.dataset.questionId;
      clearTimeout(debounceTimers[qid]);
      debounceTimers[qid] = setTimeout(function () { saveBlock(block); }, 600);
    });
    block.addEventListener('change', function () { saveBlock(block); });
  });

  // Countdown timer: recursive setTimeout anchored to the absolute
  // ends_at deadline, not a decrementing counter (the same fix this
  // app's own idle-timeout warning needed, see layouts/app.blade.php —
  // setInterval drifts/freezes under background-tab throttling, a
  // recursive setTimeout self-corrects instead of compounding).
  var timerEl = document.getElementById('cbtTimer');
  var deadline = new Date(timerEl.dataset.endsAt).getTime();
  var submitted = false;

  function formatRemaining(ms) {
    var totalSeconds = Math.max(0, Math.floor(ms / 1000));
    var m = Math.floor(totalSeconds / 60);
    var s = totalSeconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function tick() {
    var remaining = deadline - Date.now();
    if (remaining <= 0) {
      timerEl.textContent = 'Time is up — submitting...';
      if (!submitted) {
        submitted = true;
        document.getElementById('cbtSubmitForm').onsubmit = null;
        document.getElementById('cbtSubmitForm').submit();
      }
      return;
    }
    timerEl.textContent = 'Time remaining: ' + formatRemaining(remaining);
    setTimeout(tick, 1000);
  }
  tick();

  // Security profile enforcement (Phase 2c) — JS-only signals (fullscreen,
  // tab-switch, copy/paste, right-click). Every violation POSTs to the
  // event endpoint, which logs it and updates the session's risk score
  // server-side (App\Services\Cbt\RiskScoringService); a lock-tier profile
  // that crosses its own tab_switch_limit is auto-submitted as a direct
  // side effect of that same request, not a separate client-driven call.
  @php($secProfile = $session->paper->securityProfile)
  var profile = @json([
    'require_fullscreen' => $secProfile->require_fullscreen,
    'block_copy_paste' => $secProfile->block_copy_paste,
    'block_right_click' => $secProfile->block_right_click,
    'tab_switch_action' => $secProfile->tab_switch_action,
  ]);
  var eventUrl = '{{ route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]) }}';

  function reportEvent(type) {
    fetch(eventUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
      body: JSON.stringify({ type: type }),
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.auto_submitted) {
        submitted = true;
        alert('This assessment has been automatically submitted due to repeated tab switching.');
        window.location.reload();
      }
    }).catch(function () {});
  }

  if (profile.require_fullscreen) {
    document.documentElement.requestFullscreen && document.documentElement.requestFullscreen().catch(function () {});
    document.addEventListener('fullscreenchange', function () {
      if (!document.fullscreenElement && !submitted) reportEvent('fullscreen_exit');
    });
  }

  if (profile.tab_switch_action !== 'none') {
    document.addEventListener('visibilitychange', function () {
      if (document.hidden && !submitted) reportEvent('tab_switch');
    });
    window.addEventListener('blur', function () {
      if (!submitted) reportEvent('window_blur');
    });
  }

  if (profile.block_copy_paste) {
    document.addEventListener('copy', function (e) { e.preventDefault(); reportEvent('copy_attempt'); });
    document.addEventListener('paste', function (e) { e.preventDefault(); reportEvent('paste_attempt'); });
  }

  if (profile.block_right_click) {
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); reportEvent('right_click'); });
  }
})();
</script>
@endpush
@endsection
