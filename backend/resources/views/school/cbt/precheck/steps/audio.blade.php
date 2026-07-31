<h2 style="margin-bottom:.6rem">Audio check</h2>
<p class="muted" style="margin-bottom:.8rem">We first listen briefly to your room's background noise, then ask you to say something so we can confirm your microphone works.</p>
<p id="audioStatus" class="muted" style="margin-bottom:1rem">Waiting for microphone permission...</p>
<input type="hidden" name="audio_ok" id="audioOk" value="" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var status = document.getElementById('audioStatus');
  var okField = document.getElementById('audioOk');
  var continueBtn = document.getElementById('precheckContinue');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    status.textContent = 'Your browser does not support microphone access.';
    status.style.color = 'var(--bad, red)';
    return;
  }

  navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
    var AudioCtx = window.AudioContext || window.webkitAudioContext;
    var ctx = new AudioCtx();
    var source = ctx.createMediaStreamSource(stream);
    var analyser = ctx.createAnalyser();
    analyser.fftSize = 512;
    source.connect(analyser);
    var data = new Uint8Array(analyser.frequencyBinCount);
    var baseline = 0;
    var phase = 'noise';

    status.textContent = 'Measuring background noise...';

    function level() {
      analyser.getByteFrequencyData(data);
      var sum = 0;
      for (var i = 0; i < data.length; i++) sum += data[i];
      return sum / data.length;
    }

    setTimeout(function () {
      baseline = level();
      phase = 'speech';
      status.textContent = 'Now please say something out loud...';

      var tries = 0;
      var interval = setInterval(function () {
        tries++;
        var current = level();
        if (current > baseline + 8) {
          clearInterval(interval);
          status.textContent = 'Microphone confirmed.';
          status.style.color = 'var(--ok, green)';
          okField.value = '1';
          continueBtn.disabled = false;
        } else if (tries > 100) {
          clearInterval(interval);
          status.textContent = 'We could not clearly hear you, but your microphone is connected. You may continue.';
          okField.value = '1';
          continueBtn.disabled = false;
        }
      }, 100);
    }, 1500);
  }).catch(function () {
    status.textContent = 'Microphone access was blocked or unavailable. Please allow microphone access and reload this page.';
    status.style.color = 'var(--bad, red)';
  });
})();
</script>
@endpush
