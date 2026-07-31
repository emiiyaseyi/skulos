<h2 style="margin-bottom:.6rem">Close other tabs and apps</h2>
<p class="muted" style="margin-bottom:.8rem">Please close every other browser tab and application before continuing. Having other programs open during a proctored assessment may be flagged.</p>
<label style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:1rem">
  <input type="checkbox" name="confirm" value="1" id="confirmCloseTabs" style="margin-top:.2rem" />
  <span>I have closed all other tabs and applications.</span>
</label>
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
  document.getElementById('confirmCloseTabs').addEventListener('change', function (e) {
    document.getElementById('precheckContinue').disabled = !e.target.checked;
  });
</script>
@endpush
