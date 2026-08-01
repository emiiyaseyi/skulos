@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $assessment->title])
@section('title', $assessment->title)
@section('bare', true)

@section('content')
<div style="max-width:40rem;margin:1.5rem auto;padding:0 1.25rem">
  <div class="page-head" style="position:sticky;top:0;background:var(--bg);z-index:5;padding-bottom:.6rem">
    <div>
      <h1 class="title">{{ $assessment->title }}</h1>
      <p class="subtitle" id="cbtTimer" data-ends-at="{{ $session->ends_at->toIso8601String() }}">Loading time remaining...</p>
    </div>
  </div>

  @if($assessment->instructions)
    <div class="card" style="margin-bottom:1rem"><p class="muted">{{ $assessment->instructions }}</p></div>
  @endif

  <div style="margin-bottom:1rem">
    <button type="button" class="btn btn-ghost btn-sm" id="requestBreakBtn">Request a break</button>
    <span id="breakStatus" class="muted" style="font-size:.85rem;margin-left:.5rem"></span>
  </div>

  @if($session->isOnBreak())
    <div id="breakOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.75);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:50;text-align:center;padding:1rem">
      <h2 style="margin-bottom:.6rem">You are on an approved break</h2>
      <p id="breakCountdown" data-ends-at="{{ $session->break_ends_at->toIso8601String() }}">Resuming shortly...</p>
    </div>
  @endif

  <form id="cbtSubmitForm" method="POST" action="{{ route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]) }}" onsubmit="return confirm('Submit your answers? You cannot change them after this.')">
    @csrf
  </form>

  {{-- The taker's own frozen set, not the assessment's full list: with
       randomisation or shuffling on those are different, and the
       stored set is what they must see on resume and on review. --}}
  @foreach($given as $pq)
    @php $question = $pq->question; $current = $existingAnswers[$question->id] ?? null; @endphp
    <div class="card cbt-question" data-question-id="{{ $question->id }}" data-type="{{ $question->type }}" style="margin-bottom:1rem">
      <div class="muted" style="font-size:.8rem;margin-bottom:.4rem">Question {{ $loop->index + 1 }} &middot; {{ $pq->points }} point(s)</div>
      <p style="margin-bottom:.8rem">{{ $question->prompt }}</p>
      @if($question->image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($question->image_path) }}" alt="" style="max-width:100%;border-radius:8px;margin-bottom:.8rem" />
      @endif

      @if($question->type === 'multiple_choice_single' || $question->type === 'true_false')
        @foreach($question->answers as $option)
          <label style="display:block;margin-bottom:.4rem">
            <input type="radio" name="q{{ $question->id }}" value="{{ $option->id }}" @checked((string) $current === (string) $option->id) /> {{ $option->answer_text }}
          </label>
        @endforeach
      @elseif($question->type === 'multiple_choice_multiple')
        @foreach($question->answers as $option)
          <label style="display:block;margin-bottom:.4rem">
            <input type="checkbox" name="q{{ $question->id }}" value="{{ $option->id }}" @checked(is_array($current) && in_array((string) $option->id, array_map('strval', $current), true)) /> {{ $option->answer_text }}
          </label>
        @endforeach
      @elseif($question->type === 'short_answer')
        <input type="text" value="{{ $current }}" />
      @elseif($question->type === 'essay')
        <textarea rows="5">{{ $current }}</textarea>
      @elseif($question->type === 'matching')
        @php $rightOptions = $question->answers->pluck('feedback')->filter()->unique()->values(); @endphp
        @foreach($question->answers as $stem)
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
            <span style="flex:1">{{ $stem->answer_text }}</span>
            <select data-left-id="{{ $stem->id }}">
              <option value="">Choose a match</option>
              @foreach($rightOptions as $rightItem)
                <option value="{{ $rightItem }}" @selected(($current[$stem->id] ?? null) === $rightItem)>{{ $rightItem }}</option>
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

@php $secProfileScripts = $session->assessment->securityProfile; @endphp
@if($secProfileScripts->require_camera_check)
  <script src="{{ asset('vendor/proctoring/face-api.min.js') }}"></script>
@endif
@if($secProfileScripts->require_object_detection)
  <script src="{{ asset('vendor/proctoring/onnx/ort.min.js') }}"></script>
@endif

@push('scripts')
<script>
(function () {
  var sessionId = {{ $session->id }};
  var saveUrl = '{{ route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]) }}';
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

  // Break request (Phase 2g) — staff decides from the live monitor; this
  // page has no live push, so a taker on an approved break just sees a
  // countdown (the same absolute-deadline setTimeout pattern as the
  // exam timer below) and the page reloads once it lifts.
  var breakBtn = document.getElementById('requestBreakBtn');
  if (breakBtn) {
    breakBtn.addEventListener('click', function () {
      var reason = prompt('Briefly tell your invigilator why you need a break:');
      if (!reason) return;
      fetch('{{ route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]) }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify({ reason: reason }),
      }).then(function (r) {
        var status = document.getElementById('breakStatus');
        if (r.ok) {
          breakBtn.disabled = true;
          status.textContent = 'Break requested — waiting for your invigilator.';
        } else {
          status.textContent = 'Could not request a break right now.';
        }
      });
    });
  }

  var breakCountdownEl = document.getElementById('breakCountdown');
  if (breakCountdownEl) {
    var breakDeadline = new Date(breakCountdownEl.dataset.endsAt).getTime();
    (function tickBreak() {
      var remaining = breakDeadline - Date.now();
      if (remaining <= 0) { window.location.reload(); return; }
      var s = Math.ceil(remaining / 1000);
      breakCountdownEl.textContent = 'Resuming in ' + Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60) + '...';
      setTimeout(tickBreak, 1000);
    })();
  }

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
        if (sel.value !== '') answer[sel.dataset.leftId] = sel.value;
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
  @php
    // Built here rather than as a multi-line array literal inside the
    // json() directive's own argument: Blade balances directive
    // arguments with a paren-counting regex that a nested multi-line
    // array breaks, producing "Unclosed [ does not match )" in the
    // COMPILED file. Caught by CbtBladeIntegrityTest.
    //
    // Note this is the BLOCK form, and every php directive in this
    // file must stay the block form: mixing it with the inline
    // php(...) form makes Blade treat the inline one as reopening
    // raw-PHP mode, silently leaving every later directive
    // uncompiled.
    $secProfile = $session->assessment->securityProfile;
    $profileForJs = [
      'require_fullscreen' => $secProfile->require_fullscreen,
      'block_copy_paste' => $secProfile->block_copy_paste,
      'block_right_click' => $secProfile->block_right_click,
      'tab_switch_action' => $secProfile->tab_switch_action,
      'require_camera_check' => $secProfile->require_camera_check,
      'require_object_detection' => $secProfile->require_object_detection,
      'snapshot_interval_seconds' => $secProfile->snapshot_interval_seconds,
    ];
  @endphp
  var profile = @json($profileForJs);
  var eventUrl = '{{ route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]) }}';

  function reportEvent(type, meta, snapshot) {
    fetch(eventUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
      body: JSON.stringify({ type: type, meta: meta || null, snapshot: snapshot || null }),
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

  // AI proctoring (Phase 2e) — periodic face presence/identity checks
  // (face-api.js) and object detection (YOLOv5n via ONNX Runtime Web),
  // both fully client-side, no server AI call, matching the reference
  // repo's own architecture. Only started when the paper's own security
  // profile actually requires it.
  if (profile.require_camera_check || profile.require_object_detection) {
    var aiVideo = document.createElement('video');
    aiVideo.autoplay = true; aiVideo.playsInline = true; aiVideo.muted = true;
    aiVideo.style.cssText = 'position:fixed;bottom:1rem;right:1rem;width:8rem;border-radius:8px;opacity:.85;z-index:4';
    document.body.appendChild(aiVideo);

    navigator.mediaDevices && navigator.mediaDevices.getUserMedia({ video: true }).then(function (stream) {
      aiVideo.srcObject = stream;
      if (profile.require_camera_check) startFaceMonitoring(aiVideo);
      if (profile.require_object_detection) startObjectMonitoring(aiVideo);
    }).catch(function () {});
  }

  // A snapshot is only worth capturing alongside a genuinely concerning
  // signal (Phase 2g) — never on every routine check, that would flood
  // local disk with evidence nobody needs to review.
  function snapshotOf(video) {
    var canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 320;
    canvas.height = video.videoHeight || 240;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg', 0.7);
  }

  function startFaceMonitoring(video) {
    if (typeof faceapi === 'undefined') return;
    var modelUrl = '{{ asset('vendor/proctoring/models') }}';
    var stored = null;
    try {
      var raw = sessionStorage.getItem('cbt_face_descriptor');
      stored = raw ? Float32Array.from(JSON.parse(raw)) : null;
    } catch (e) {}
    var intervalMs = (profile.snapshot_interval_seconds || 30) * 1000;

    Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
      faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl),
    ]).then(function () {
      setInterval(function () {
        if (submitted || video.readyState < 2) return;
        faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
          .then(function (det) {
            if (!det) { reportEvent('face_not_detected', null, snapshotOf(video)); return; }
            if (stored) {
              var distance = faceapi.euclideanDistance(stored, det.descriptor);
              if (distance > 0.6) reportEvent('face_mismatch', { distance: Math.round(distance * 1000) / 1000 }, snapshotOf(video));
            }
          }).catch(function () {});
      }, intervalMs);
    }).catch(function () {});
  }

  // COCO classes YOLOv5n was trained on; only a small "concerning in an
  // exam" subset actually raises an event (matches the reference repo's
  // own OBJECT_VIOLATIONS list) — everything else is silently ignored.
  var CBT_YOLO_CLASSES = [
    'person', 'bicycle', 'car', 'motorcycle', 'airplane', 'bus', 'train', 'truck', 'boat',
    'traffic light', 'fire hydrant', 'stop sign', 'parking meter', 'bench', 'bird', 'cat',
    'dog', 'horse', 'sheep', 'cow', 'elephant', 'bear', 'zebra', 'giraffe', 'backpack',
    'umbrella', 'handbag', 'tie', 'suitcase', 'frisbee', 'skis', 'snowboard', 'sports ball',
    'kite', 'baseball bat', 'baseball glove', 'skateboard', 'surfboard', 'tennis racket',
    'bottle', 'wine glass', 'cup', 'fork', 'knife', 'spoon', 'bowl', 'banana', 'apple',
    'sandwich', 'orange', 'broccoli', 'carrot', 'hot dog', 'pizza', 'donut', 'cake', 'chair',
    'couch', 'potted plant', 'bed', 'dining table', 'toilet', 'tv', 'laptop', 'mouse',
    'remote', 'keyboard', 'cell phone', 'microwave', 'oven', 'toaster', 'sink',
    'refrigerator', 'book', 'clock', 'vase', 'scissors', 'teddy bear', 'hair drier', 'toothbrush',
  ];
  var CBT_OBJECT_VIOLATIONS = ['cell phone', 'book', 'laptop', 'remote', 'keyboard'];

  function startObjectMonitoring(video) {
    if (typeof ort === 'undefined') return;
    var onnxUrl = '{{ asset('vendor/proctoring/onnx/yolov5n.onnx') }}';
    var size = 416;
    var session = null;

    ort.InferenceSession.create(onnxUrl, { executionProviders: ['wasm'], graphOptimizationLevel: 'all' })
      .then(function (s) {
        session = s;
        setTimeout(runYolo, 8000);
        setInterval(runYolo, 25000);
      }).catch(function () {});

    function runYolo() {
      if (submitted || !session || video.readyState < 2) return;
      var canvas = document.createElement('canvas');
      canvas.width = size; canvas.height = size;
      canvas.getContext('2d').drawImage(video, 0, 0, size, size);
      var data = canvas.getContext('2d').getImageData(0, 0, size, size).data;
      var tensorData = new Float32Array(3 * size * size);
      for (var i = 0; i < size * size; i++) {
        tensorData[i] = data[i * 4] / 255;
        tensorData[i + size * size] = data[i * 4 + 1] / 255;
        tensorData[i + 2 * size * size] = data[i * 4 + 2] / 255;
      }
      session.run({ images: new ort.Tensor('float32', tensorData, [1, 3, size, size]) })
        .then(function (out) { processYoloPredictions(out[Object.keys(out)[0]].data); })
        .catch(function () {});
    }

    function processYoloPredictions(preds) {
      var personCount = 0;
      for (var r = 0; r < preds.length; r += 85) {
        var objectness = preds[r + 4];
        if (objectness < 0.4) continue;
        var maxScore = 0, classId = 0;
        for (var c = 5; c < 85; c++) {
          if (preds[r + c] > maxScore) { maxScore = preds[r + c]; classId = c - 5; }
        }
        var score = objectness * maxScore;
        if (score < 0.35) continue;
        var cls = (CBT_YOLO_CLASSES[classId] || '').toLowerCase();
        if (cls === 'person') { personCount++; continue; }
        if (CBT_OBJECT_VIOLATIONS.indexOf(cls) !== -1) {
          reportEvent('object_detected', { class: cls, confidence: Math.round(score * 100) }, snapshotOf(video));
        }
      }
      if (personCount > 1) {
        reportEvent('object_detected', { class: 'multiple_persons', count: personCount }, snapshotOf(video));
      }
    }
  }
})();
</script>
@endpush
@endsection
