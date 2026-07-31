<h2 style="margin-bottom:.6rem">Environment scan</h2>
<p class="muted" style="margin-bottom:.8rem">Slowly pan your camera around your workspace so we can confirm your surroundings are clear. Recording stops automatically after 15 seconds.</p>
<video id="envPreview" autoplay playsinline muted style="width:100%;border-radius:8px;background:#000;margin-bottom:.6rem"></video>
<button type="button" class="btn btn-ghost btn-block" id="recordBtn" style="margin-bottom:.8rem" disabled>Start recording</button>
<p id="envStatus" class="muted" style="margin-bottom:1rem">Not yet recorded.</p>
<input type="file" name="environment_video" id="envFileInput" style="display:none" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var video = document.getElementById('envPreview');
  var status = document.getElementById('envStatus');
  var recordBtn = document.getElementById('recordBtn');
  var fileInput = document.getElementById('envFileInput');
  var continueBtn = document.getElementById('precheckContinue');
  var stream, recorder, chunks = [];

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    status.textContent = 'Your browser does not support camera recording.';
    return;
  }

  navigator.mediaDevices.getUserMedia({ video: true }).then(function (s) {
    stream = s;
    video.srcObject = s;
    recordBtn.disabled = false;
  }).catch(function () {
    status.textContent = 'Camera access was blocked. Please allow camera access and reload this page.';
  });

  recordBtn.addEventListener('click', function () {
    if (!stream || !window.MediaRecorder) {
      status.textContent = 'Recording is not supported in this browser.';
      return;
    }
    chunks = [];
    recorder = new MediaRecorder(stream, { mimeType: 'video/webm' });
    recorder.ondataavailable = function (e) { if (e.data.size > 0) chunks.push(e.data); };
    recorder.onstop = function () {
      var blob = new Blob(chunks, { type: 'video/webm' });
      var file = new File([blob], 'environment.webm', { type: 'video/webm' });
      var dt = new DataTransfer();
      dt.items.add(file);
      fileInput.files = dt.files;
      status.textContent = 'Recording captured.';
      status.style.color = 'var(--ok, green)';
      continueBtn.disabled = false;
      recordBtn.textContent = 'Re-record';
    };
    recorder.start();
    status.textContent = 'Recording... slowly pan around your space.';
    recordBtn.disabled = true;
    setTimeout(function () {
      if (recorder.state !== 'inactive') recorder.stop();
      recordBtn.disabled = false;
    }, 15000);
  });
})();
</script>
@endpush
