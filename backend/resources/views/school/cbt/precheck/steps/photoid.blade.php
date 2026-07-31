<h2 style="margin-bottom:.6rem">Photo ID</h2>
<p class="muted" style="margin-bottom:.8rem">Upload a clear photo of a valid ID (school ID, national ID, or similar). Make sure your name and photo are visible.</p>
<input type="file" name="photo_id" accept="image/*" id="photoIdInput" style="margin-bottom:1rem" />
<button class="btn btn-primary btn-block" type="submit" id="precheckContinue" disabled>Continue</button>

@push('scripts')
<script>
  document.getElementById('photoIdInput').addEventListener('change', function (e) {
    document.getElementById('precheckContinue').disabled = !(e.target.files && e.target.files.length);
  });
</script>
@endpush
