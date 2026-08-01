{{-- The take screen's own JS. Split out of session.blade.php to keep
     that file readable — see its own docblock comment about why every
     @php block in these two files stays the BLOCK form, never mixed
     with inline @php(...), and why directive arguments never carry a
     multi-line array literal: both silently break Blade's compiler
     (CbtBladeIntegrityTest guards against a regression here). --}}
@push('scripts')
<script>
(function () {
  var sessionId = {{ $session->id }};
  var saveUrl = '{{ $routes['answer'] }}';
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
  var submitted = false;

  // ── Page model: every .lg-question under the scroll container, in
  //    document order — a "page" is either a real question or a
  //    section banner. Visibility is decided here, in JS, not trusted
  //    from Blade's own initial `hidden` attributes (those exist only
  //    as a reasonable no-JS fallback ordering). ────────────────────
  var pageEls = Array.prototype.slice.call(document.querySelectorAll('#lgQuestionScroll > .lg-question'));
  var questionPages = pageEls.filter(function (el) { return el.classList.contains('cbt-question'); });
  var currentPageIndex = 0;
  var flagged = {};

  pageEls.forEach(function (el, i) { el.hidden = (i !== 0); });

  function isQuestionPage(el) { return !!el && el.classList.contains('cbt-question'); }

  // ── Saving answers ──────────────────────────────────────────────
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
      var input = block.querySelector('.lg-input');
      return input ? input.value : null;
    }
    if (type === 'essay') {
      var ta = block.querySelector('.lg-textarea');
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

  function isAnswered(block) {
    var a = getAnswer(block);
    if (a === null || a === '') return false;
    if (Array.isArray(a)) return a.length > 0;
    if (typeof a === 'object') return Object.keys(a).length > 0;
    return true;
  }

  var debounceTimers = {};
  function saveBlock(block) {
    var status = block.querySelector('[data-save-status]');
    if (status) status.textContent = 'Saving...';
    return fetch(saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
      body: JSON.stringify({ question_id: block.dataset.questionId, answer: getAnswer(block) }),
    }).then(function (r) {
      if (status) status.textContent = r.ok ? 'Saved' : 'Not saved — check your connection';
      updateChrome();
      return r.ok;
    }).catch(function () {
      if (status) status.textContent = 'Not saved — check your connection';
      return false;
    });
  }

  /** Clears any pending debounced save and saves right now — used when leaving a page, so a fast Next never races the 600ms debounce. */
  function flushSave(block) {
    if (!isQuestionPage(block)) return Promise.resolve(true);
    var qid = block.dataset.questionId;
    clearTimeout(debounceTimers[qid]);
    return saveBlock(block);
  }

  questionPages.forEach(function (block) {
    block.addEventListener('input', function () {
      var qid = block.dataset.questionId;
      clearTimeout(debounceTimers[qid]);
      debounceTimers[qid] = setTimeout(function () { saveBlock(block); }, 600);
    });
    block.addEventListener('change', function () { saveBlock(block); });
  });

  // ── Navigation between pages ────────────────────────────────────
  function goToPage(index) {
    if (index < 0 || index >= pageEls.length) return;
    var leaving = pageEls[currentPageIndex];
    flushSave(leaving);
    pageEls[currentPageIndex].hidden = true;
    currentPageIndex = index;
    pageEls[currentPageIndex].hidden = false;
    var scroller = document.getElementById('lgQuestionScroll');
    if (scroller) scroller.scrollTop = 0;
    updateChrome();
  }

  function questionPageIndexOf(el) { return questionPages.indexOf(el); }

  document.getElementById('lgPrev').addEventListener('click', function () { goToPage(currentPageIndex - 1); });
  document.getElementById('lgNext').addEventListener('click', function () { goToPage(currentPageIndex + 1); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-enter-section]'), function (btn) {
    btn.addEventListener('click', function () { goToPage(currentPageIndex + 1); });
  });

  document.getElementById('lgFlag').addEventListener('click', function () {
    var page = pageEls[currentPageIndex];
    if (!isQuestionPage(page)) return;
    var qid = page.dataset.questionId;
    flagged[qid] = !flagged[qid];
    updateChrome();
  });

  // ── Navigator panel ─────────────────────────────────────────────
  var navPanel = document.getElementById('lgNavPanel');
  var navGrid = document.getElementById('lgNavGrid');
  document.getElementById('lgNavToggle').addEventListener('click', function () {
    navPanel.hidden = !navPanel.hidden;
    if (!navPanel.hidden) renderNav();
  });

  function renderNav() {
    navGrid.innerHTML = '';
    questionPages.forEach(function (page, i) {
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'lg-navitem';
      item.textContent = String(i + 1);
      if (isAnswered(page)) item.classList.add('is-answered');
      if (flagged[page.dataset.questionId]) item.classList.add('is-flagged');
      if (page === pageEls[currentPageIndex]) item.classList.add('is-current');
      item.addEventListener('click', function () {
        goToPage(pageEls.indexOf(page));
        navPanel.hidden = true;
      });
      navGrid.appendChild(item);
    });
  }

  // ── Header chrome: progress, counts, prev/next/submit swap ──────
  var progressText = document.getElementById('lgProgressText');
  var progressBar = document.getElementById('lgProgressBar');
  var answeredEl = document.getElementById('lgAnswered');
  var remainingEl = document.getElementById('lgRemaining');
  var flaggedEl = document.getElementById('lgFlagged');
  var prevBtn = document.getElementById('lgPrev');
  var nextBtn = document.getElementById('lgNext');
  var submitBtn = document.getElementById('lgSubmit');

  function updateChrome() {
    var page = pageEls[currentPageIndex];
    var qIndex = isQuestionPage(page) ? questionPageIndexOf(page) : -1;
    var total = questionPages.length;

    if (qIndex >= 0) {
      progressText.textContent = 'Q ' + (qIndex + 1) + ' of ' + total;
      progressBar.style.width = Math.round(((qIndex + 1) / total) * 100) + '%';
    }

    var answeredCount = questionPages.filter(isAnswered).length;
    answeredEl.textContent = String(answeredCount);
    remainingEl.textContent = String(total - answeredCount);
    flaggedEl.textContent = String(Object.keys(flagged).filter(function (k) { return flagged[k]; }).length);

    prevBtn.disabled = currentPageIndex === 0;
    document.getElementById('lgFlag').disabled = !isQuestionPage(page);

    var isLastPage = currentPageIndex === pageEls.length - 1;
    nextBtn.hidden = isLastPage;
    submitBtn.hidden = !isLastPage;

    if (!navPanel.hidden) renderNav();
  }

  // ── Submit ───────────────────────────────────────────────────────
  var confirmOverlay = document.getElementById('lgConfirm');
  var confirmBody = document.getElementById('lgConfirmBody');
  var submitForm = document.getElementById('cbtSubmitForm');

  function openConfirm() {
    var total = questionPages.length;
    var answeredCount = questionPages.filter(isAnswered).length;
    confirmBody.textContent = answeredCount < total
      ? ('You have answered ' + answeredCount + ' of ' + total + ' question(s). You cannot change your answers after submitting.')
      : 'You cannot change your answers after this.';
    confirmOverlay.hidden = false;
  }

  submitBtn.addEventListener('click', openConfirm);
  document.getElementById('lgConfirmCancel').addEventListener('click', function () { confirmOverlay.hidden = true; });
  document.getElementById('lgConfirmGo').addEventListener('click', function () {
    finishBySubmitting();
  });

  function finishBySubmitting() {
    if (submitted) return;
    submitted = true;
    var timerEl = document.getElementById('cbtTimer');
    if (timerEl) timerEl.textContent = 'Submitting...';
    flushSave(pageEls[currentPageIndex]).finally(function () {
      submitForm.submit();
    });
  }

  // ── Scratch pad (local only — never submitted) ──────────────────
  var notesPanel = document.getElementById('lgNotes');
  var notesText = document.getElementById('lgNotesText');
  var notesKey = 'cbt_notes_' + sessionId;
  try { notesText.value = localStorage.getItem(notesKey) || ''; } catch (e) {}
  notesText.addEventListener('input', function () {
    try { localStorage.setItem(notesKey, notesText.value); } catch (e) {}
  });
  document.getElementById('lgNotesBtn').addEventListener('click', function () { notesPanel.hidden = !notesPanel.hidden; });

  // ── Calculator ───────────────────────────────────────────────────
  var calcPanel = document.getElementById('lgCalc');
  var calcDisplay = document.getElementById('lgCalcDisplay');
  var calcValue = '0';
  document.getElementById('lgCalcBtn').addEventListener('click', function () { calcPanel.hidden = !calcPanel.hidden; });

  function calcRender() { calcDisplay.value = calcValue; }
  function calcCompute(expr) {
    // A tiny, safe evaluator: digits/operators only, never eval().
    if (!/^[0-9+\-×÷%.\s]*$/.test(expr)) return 'Error';
    var normalized = expr.replace(/×/g, '*').replace(/÷/g, '/').replace(/%/g, '/100');
    try {
      var tokens = normalized.match(/(\d+\.?\d*|[+\-*/])/g) || [];
      if (!tokens.length) return '0';
      var result = parseFloat(tokens[0]);
      for (var i = 1; i < tokens.length; i += 2) {
        var op = tokens[i], next = parseFloat(tokens[i + 1]);
        if (next === undefined || isNaN(next)) break;
        if (op === '+') result += next;
        else if (op === '-') result -= next;
        else if (op === '*') result *= next;
        else if (op === '/') result = next === 0 ? NaN : result / next;
      }
      return isNaN(result) ? 'Error' : String(Math.round(result * 1e8) / 1e8);
    } catch (e) { return 'Error'; }
  }

  document.getElementById('lgCalcKeys').addEventListener('click', function (e) {
    var key = e.target.dataset.key;
    if (!key) return;
    if (key === 'C') { calcValue = '0'; }
    else if (key === '⌫') { calcValue = calcValue.length > 1 ? calcValue.slice(0, -1) : '0'; }
    else if (key === '=') { calcValue = calcCompute(calcValue); }
    else if (key === '±') { calcValue = calcValue.charAt(0) === '-' ? calcValue.slice(1) : ('-' + calcValue); }
    else { calcValue = (calcValue === '0' && /[0-9]/.test(key)) ? key : calcValue + key; }
    calcRender();
  });
  calcRender();

  // ── Zoom (text size) ─────────────────────────────────────────────
  var zoomLevels = [0.9, 1, 1.1, 1.25, 1.4];
  var zoomIndex = 1;
  try {
    var storedZoom = parseInt(sessionStorage.getItem('cbt_zoom_index') || '1', 10);
    if (storedZoom >= 0 && storedZoom < zoomLevels.length) zoomIndex = storedZoom;
  } catch (e) {}
  function applyZoom() {
    document.getElementById('lgShell').style.setProperty('--lg-zoom', zoomLevels[zoomIndex]);
    document.getElementById('lgZoomLabel').textContent = Math.round(zoomLevels[zoomIndex] * 100) + '%';
    try { sessionStorage.setItem('cbt_zoom_index', String(zoomIndex)); } catch (e) {}
  }
  document.getElementById('lgZoomIn').addEventListener('click', function () {
    zoomIndex = Math.min(zoomLevels.length - 1, zoomIndex + 1); applyZoom();
  });
  document.getElementById('lgZoomOut').addEventListener('click', function () {
    zoomIndex = Math.max(0, zoomIndex - 1); applyZoom();
  });
  applyZoom();

  // ── Break request (staff decides from the live monitor, when
  //    proctoring is on; in light mode there is no live monitor to
  //    approve one, so this control simply has nothing to reach —
  //    left in place rather than hidden, since a school may still
  //    switch proctoring on later without a separate UI change). ───
  var breakBtn = document.getElementById('requestBreakBtn');
  if (breakBtn) {
    breakBtn.addEventListener('click', function () {
      var reason = prompt('Briefly tell your invigilator why you need a break:');
      if (!reason) return;
      fetch('{{ $routes['break'] }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify({ reason: reason }),
      }).then(function (r) {
        var status = document.getElementById('breakStatus');
        if (r.ok) {
          breakBtn.disabled = true;
          status.textContent = 'Break requested, waiting for your invigilator.';
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

  // ── Exam clock: total, or split per question bank ────────────────
  //
  // sectionMeta carries each section's own cumulative deadline
  // (computed once, server-side, from the session's real started_at —
  // see ExamSectionService), so navigating back and forth never
  // rewards or re-derives extra time. The overall session deadline
  // (ends_at) always stays the true hard stop underneath, in case a
  // school's configured section minutes do not add up to the whole
  // duration.
  var timerEl = document.getElementById('cbtTimer');
  var overallDeadline = new Date(timerEl.dataset.endsAt).getTime();
  var perSectionTimer = @json($perSectionTimer);
  var sectionByOrder = {};
  @json($sectionMeta).forEach(function (s) { sectionByOrder[s.order] = s; });
  var sectionAdvanceLock = {};

  function formatRemaining(ms) {
    var totalSeconds = Math.max(0, Math.floor(ms / 1000));
    var m = Math.floor(totalSeconds / 60);
    var s = totalSeconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function currentSectionOrder() {
    var page = pageEls[currentPageIndex];
    if (!page || page.dataset.sectionOrder === undefined) return null;
    return Number(page.dataset.sectionOrder);
  }

  function advanceFromExpiredSection(order) {
    var nextIdx = -1;
    for (var i = 0; i < pageEls.length; i++) {
      var o = pageEls[i].dataset.sectionOrder;
      if (o !== undefined && Number(o) > order) { nextIdx = i; break; }
    }
    if (nextIdx === -1) { finishBySubmitting(); return; }
    if (currentPageIndex < nextIdx) {
      alert('Time for that section is up. Moving you on to the next section.');
      goToPage(nextIdx);
    }
  }

  function tick() {
    if (submitted) return;
    var now = Date.now();
    if (now >= overallDeadline) { finishBySubmitting(); return; }

    if (perSectionTimer) {
      var order = currentSectionOrder();
      var meta = order !== null ? sectionByOrder[order] : null;
      if (meta && meta.ends_at) {
        var sectionDeadline = new Date(meta.ends_at).getTime();
        if (now >= sectionDeadline && !sectionAdvanceLock[order]) {
          sectionAdvanceLock[order] = true;
          advanceFromExpiredSection(order);
        }
        var remaining = Math.max(0, sectionDeadline - now);
        timerEl.textContent = 'Section time: ' + formatRemaining(remaining);
        timerEl.classList.toggle('lg-timer-low', remaining > 0 && remaining < 60000);
        setTimeout(tick, 1000);
        return;
      }
    }

    var overallRemaining = overallDeadline - now;
    timerEl.textContent = formatRemaining(overallRemaining);
    timerEl.classList.toggle('lg-timer-low', overallRemaining < 60000);
    setTimeout(tick, 1000);
  }
  tick();

  // ── Security profile enforcement (proctoring; every flag below is
  //    already false in light mode — see AssessmentMode — so this
  //    whole block naturally no-ops until a school switches it on). ─
  var profile = @json($profileForJs);
  var eventUrl = '{{ $routes['event'] }}';

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

  // AI proctoring — periodic face presence/identity checks (face-api.js)
  // and object detection (YOLOv5n via ONNX Runtime Web), fully
  // client-side, no server AI call. Only started when the assessment's
  // own security profile actually requires it (and so, only ever in
  // proctoring mode).
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

  updateChrome();
})();
</script>
@endpush
