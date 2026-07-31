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

@php($secProfileScripts = $session->paper->securityProfile)
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
    'require_camera_check' => $secProfile->require_camera_check,
    'require_object_detection' => $secProfile->require_object_detection,
    'snapshot_interval_seconds' => $secProfile->snapshot_interval_seconds,
  ]);
  var eventUrl = '{{ route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]) }}';

  function reportEvent(type, meta) {
    fetch(eventUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
      body: JSON.stringify({ type: type, meta: meta || null }),
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
            if (!det) { reportEvent('face_not_detected'); return; }
            if (stored) {
              var distance = faceapi.euclideanDistance(stored, det.descriptor);
              if (distance > 0.6) reportEvent('face_mismatch', { distance: Math.round(distance * 1000) / 1000 });
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
          reportEvent('object_detected', { class: cls, confidence: Math.round(score * 100) });
        }
      }
      if (personCount > 1) {
        reportEvent('object_detected', { class: 'multiple_persons', count: personCount });
      }
    }
  }
})();
</script>
@endpush
@endsection
