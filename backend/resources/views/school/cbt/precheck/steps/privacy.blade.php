<h2 style="margin-bottom:.6rem">Privacy consent</h2>
<div id="privacyScrollBox" style="max-height:12rem;overflow-y:auto;border:1px solid var(--border,#ddd);border-radius:8px;padding:.8rem;margin-bottom:.8rem;font-size:.9rem">
  <p class="muted" style="margin-bottom:.6rem">During this proctored assessment we may capture and store your webcam images, screen activity, and browser events for the sole purpose of verifying academic integrity.</p>
  <p class="muted" style="margin-bottom:.6rem">This data is visible only to authorised staff reviewing your attempt and is retained only as long as your school's own record-keeping policy requires.</p>
  <p class="muted" style="margin-bottom:.6rem">You may pause and request a break during the assessment if you need one; unusual activity may be flagged for staff review, and does not by itself mean your attempt is invalid.</p>
  <p class="muted">By continuing, you consent to this monitoring for the duration of this attempt only.</p>
</div>
<label style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1rem">
  <input type="checkbox" name="agree" value="1" id="agreeCheckbox" style="margin-top:.2rem" disabled />
  <span>I have read and agree to the above.</span>
</label>
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
(function () {
  var box = document.getElementById('privacyScrollBox');
  var checkbox = document.getElementById('agreeCheckbox');
  var continueBtn = document.getElementById('precheckContinue');

  function checkScrolled() {
    if (box.scrollTop + box.clientHeight >= box.scrollHeight - 4) {
      checkbox.disabled = false;
    }
  }
  box.addEventListener('scroll', checkScrolled);
  checkScrolled(); // short content may not need scrolling at all

  checkbox.addEventListener('change', function (e) {
    continueBtn.disabled = !e.target.checked;
  });
})();
</script>
@endpush
