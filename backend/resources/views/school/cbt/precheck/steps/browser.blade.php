<h2 style="margin-bottom:.6rem">Browser check</h2>
<p class="muted" style="margin-bottom:.8rem">We ran a quick check of your browser. This is informational and will not stop you from continuing.</p>
<ul id="browserChecks" style="margin-bottom:1rem;padding-left:1.1rem"></ul>
<button class="btn btn-primary btn-block" type="submit">Continue</button>

@push('scripts')
<script>
(function () {
  var list = document.getElementById('browserChecks');
  var checks = [
    ['Cookies enabled', navigator.cookieEnabled === true],
    ['JavaScript enabled', true],
    ['Modern browser (camera/screen APIs available)', !!(navigator.mediaDevices && window.RTCPeerConnection)],
  ];
  checks.forEach(function (c) {
    var li = document.createElement('li');
    li.textContent = c[0] + ': ' + (c[1] ? 'OK' : 'Not detected');
    li.style.color = c[1] ? 'var(--ok, green)' : 'var(--warn, orange)';
    list.appendChild(li);
  });
})();
</script>
@endpush
