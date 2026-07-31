<h2 style="margin-bottom:.6rem">Face verification</h2>
<p class="muted" style="margin-bottom:.8rem">Look directly at your camera and capture a clear photo of your face, matching the ID you just uploaded.</p>
<video id="facePreview" autoplay playsinline muted style="width:100%;border-radius:8px;background:#000;margin-bottom:.6rem"></video>
<canvas id="faceCanvas" style="display:none"></canvas>
<button type="button" class="btn btn-ghost btn-block" id="captureFaceBtn" style="margin-bottom:.8rem">Capture photo</button>
<p id="faceStatus" class="muted" style="margin-bottom:1rem">Camera loading...</p>
<input type="hidden" name="face_capture" id="faceCapture" value="" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var video = document.getElementById('facePreview');
  var canvas = document.getElementById('faceCanvas');
  var status = document.getElementById('faceStatus');
  var captureBtn = document.getElementById('captureFaceBtn');
  var field = document.getElementById('faceCapture');
  var continueBtn = document.getElementById('precheckContinue');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    status.textContent = 'Your browser does not support camera access.';
    return;
  }

  navigator.mediaDevices.getUserMedia({ video: true }).then(function (stream) {
    video.srcObject = stream;
    status.textContent = 'Ready. Click "Capture photo" when your face is clearly visible.';
  }).catch(function () {
    status.textContent = 'Camera access was blocked. Please allow camera access and reload this page.';
  });

  captureBtn.addEventListener('click', function () {
    canvas.width = video.videoWidth || 480;
    canvas.height = video.videoHeight || 360;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    field.value = canvas.toDataURL('image/jpeg', 0.85);
    status.textContent = 'Photo captured.';
    status.style.color = 'var(--ok, green)';
    continueBtn.disabled = false;
  });
})();
</script>
@endpush
