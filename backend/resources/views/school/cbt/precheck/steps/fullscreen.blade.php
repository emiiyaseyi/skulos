<h2 style="margin-bottom:.6rem">Fullscreen mode</h2>
<p class="muted" style="margin-bottom:.8rem">This assessment must be taken in fullscreen. Leaving fullscreen during the attempt will be flagged.</p>
<button type="button" class="btn btn-ghost btn-block" id="fsBtn" style="margin-bottom:.8rem">Enter fullscreen</button>
<p id="fsStatus" class="muted" style="margin-bottom:1rem">Not yet in fullscreen.</p>
<input type="hidden" name="fullscreen_ok" id="fsOk" value="" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var status = document.getElementById('fsStatus');
  var okField = document.getElementById('fsOk');
  var continueBtn = document.getElementById('precheckContinue');

  document.getElementById('fsBtn').addEventListener('click', function () {
    var el = document.documentElement;
    if (el.requestFullscreen) {
      el.requestFullscreen().catch(function () {
        status.textContent = 'Could not enter fullscreen. Please try again.';
      });
    }
  });

  document.addEventListener('fullscreenchange', function () {
    if (document.fullscreenElement) {
      status.textContent = 'Fullscreen active.';
      status.style.color = 'var(--ok, green)';
      okField.value = '1';
      continueBtn.disabled = false;
    }
  });
})();
</script>
@endpush
