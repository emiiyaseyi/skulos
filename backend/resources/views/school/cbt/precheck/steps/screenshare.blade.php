<h2 style="margin-bottom:.6rem">Screen share</h2>
<p class="muted" style="margin-bottom:.8rem">This assessment requires sharing your whole screen (not a single tab or window) for the duration of the attempt.</p>
<button type="button" class="btn btn-ghost btn-block" id="shareBtn" style="margin-bottom:.8rem">Share my screen</button>
<p id="shareStatus" class="muted" style="margin-bottom:1rem">Not yet shared.</p>
<input type="hidden" name="screenshare_ok" id="shareOk" value="" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var status = document.getElementById('shareStatus');
  var okField = document.getElementById('shareOk');
  var continueBtn = document.getElementById('precheckContinue');

  document.getElementById('shareBtn').addEventListener('click', function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
      status.textContent = 'Your browser does not support screen sharing.';
      status.style.color = 'var(--bad, red)';
      return;
    }
    navigator.mediaDevices.getDisplayMedia({ video: true }).then(function (stream) {
      var track = stream.getVideoTracks()[0];
      var settings = track.getSettings ? track.getSettings() : {};
      if (settings.displaySurface && settings.displaySurface !== 'monitor') {
        status.textContent = 'Please share your entire screen, not a single tab or window.';
        status.style.color = 'var(--warn, orange)';
        track.stop();
        return;
      }
      status.textContent = 'Whole screen shared.';
      status.style.color = 'var(--ok, green)';
      okField.value = '1';
      continueBtn.disabled = false;
    }).catch(function () {
      status.textContent = 'Screen share was cancelled or blocked.';
      status.style.color = 'var(--bad, red)';
    });
  });
})();
</script>
@endpush
