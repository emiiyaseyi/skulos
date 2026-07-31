<h2 style="margin-bottom:.6rem">Ready to begin</h2>
<p class="muted" style="margin-bottom:.6rem">During this assessment, the following are not allowed:</p>
<div id="readyScrollBox" style="max-height:10rem;overflow-y:auto;border:1px solid var(--border,#ddd);border-radius:8px;padding:.8rem;margin-bottom:.8rem;font-size:.9rem">
  <ul style="padding-left:1.1rem;margin:0">
    <li>Leaving fullscreen or switching to another tab or application</li>
    <li>Copying, pasting, or right-clicking on the assessment page</li>
    <li>A second person, phone, book, or notes visible to your camera</li>
    <li>Sharing your screen with anyone else</li>
    <li>Receiving help from another person</li>
  </ul>
  <p class="muted" style="margin-top:.6rem">The timer starts the moment you click "Begin assessment" below and cannot be paused except through an approved break request.</p>
</div>
<label style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1rem">
  <input type="checkbox" name="confirm_ready" value="1" id="readyCheckbox" style="margin-top:.2rem" disabled />
  <span>I understand and am ready to begin.</span>
</label>
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Begin assessment</button>

@push('scripts')
<script>
(function () {
  var box = document.getElementById('readyScrollBox');
  var checkbox = document.getElementById('readyCheckbox');
  var continueBtn = document.getElementById('precheckContinue');

  function checkScrolled() {
    if (box.scrollTop + box.clientHeight >= box.scrollHeight - 4) {
      checkbox.disabled = false;
    }
  }
  box.addEventListener('scroll', checkScrolled);
  checkScrolled();

  checkbox.addEventListener('change', function (e) {
    continueBtn.disabled = !e.target.checked;
  });
})();
</script>
@endpush
