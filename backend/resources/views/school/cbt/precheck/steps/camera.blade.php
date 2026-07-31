<h2 style="margin-bottom:.6rem">Camera check</h2>
<p class="muted" style="margin-bottom:.8rem">This assessment requires a working camera. Allow access when your browser asks.</p>
<video id="cameraPreview" autoplay playsinline muted style="width:100%;border-radius:8px;background:#000;margin-bottom:.8rem"></video>
<p id="cameraStatus" class="muted" style="margin-bottom:1rem">Waiting for camera permission...</p>
<input type="hidden" name="camera_ok" id="cameraOk" value="" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var video = document.getElementById('cameraPreview');
  var status = document.getElementById('cameraStatus');
  var okField = document.getElementById('cameraOk');
  var continueBtn = document.getElementById('precheckContinue');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    status.textContent = 'Your browser does not support camera access.';
    status.style.color = 'var(--bad, red)';
    return;
  }

  navigator.mediaDevices.getUserMedia({ video: true }).then(function (stream) {
    video.srcObject = stream;
    status.textContent = 'Camera detected.';
    status.style.color = 'var(--ok, green)';
    okField.value = '1';
    continueBtn.disabled = false;
  }).catch(function () {
    status.textContent = 'Camera access was blocked or unavailable. Please allow camera access and reload this page.';
    status.style.color = 'var(--bad, red)';
  });
})();
</script>
@endpush
