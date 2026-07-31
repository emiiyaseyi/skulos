<h2 style="margin-bottom:.6rem">Internet check</h2>
<p class="muted" style="margin-bottom:.8rem">A stable connection helps your answers save without interruption. This is informational and will not stop you from continuing.</p>
<p id="internetStatus" class="muted" style="margin-bottom:1rem">Checking...</p>
<button class="btn btn-primary btn-block" type="submit">Continue</button>

@push('scripts')
<script>
(function () {
  var el = document.getElementById('internetStatus');
  if (!navigator.onLine) {
    el.textContent = 'You appear to be offline. Please reconnect before continuing.';
    el.style.color = 'var(--bad, red)';
    return;
  }
  var start = Date.now();
  fetch(window.location.href, { method: 'HEAD', cache: 'no-store' }).then(function () {
    el.textContent = 'Connected (' + (Date.now() - start) + 'ms response time).';
    el.style.color = 'var(--ok, green)';
  }).catch(function () {
    el.textContent = 'Could not verify your connection speed, but you appear online.';
  });
})();
</script>
@endpush
