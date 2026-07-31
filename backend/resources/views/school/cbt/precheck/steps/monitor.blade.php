<h2 style="margin-bottom:.6rem">Monitor check</h2>
<p class="muted" style="margin-bottom:.8rem">Only one display may be connected during this assessment.</p>
<p id="monitorStatus" class="muted" style="margin-bottom:.8rem">Checking for connected displays...</p>
<label id="monitorConfirmRow" style="display:none;align-items:flex-start;gap:.5rem;margin-bottom:1rem">
  <input type="checkbox" name="monitor_ok" value="1" id="monitorOk" style="margin-top:.2rem" />
  <span>I confirm I am using only one monitor.</span>
</label>
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var status = document.getElementById('monitorStatus');
  var row = document.getElementById('monitorConfirmRow');
  var checkbox = document.getElementById('monitorOk');
  var continueBtn = document.getElementById('precheckContinue');

  if (window.screen && 'isExtended' in window.screen) {
    if (window.screen.isExtended === true) {
      status.textContent = 'Multiple displays detected. Please disconnect any extra monitor before continuing.';
      status.style.color = 'var(--warn, orange)';
    } else {
      status.textContent = 'Single display detected.';
      status.style.color = 'var(--ok, green)';
    }
  } else {
    status.textContent = 'We could not automatically detect your display count. Please confirm below.';
  }
  row.style.display = 'flex';

  checkbox.addEventListener('change', function (e) {
    continueBtn.disabled = !e.target.checked;
  });
})();
</script>
@endpush
