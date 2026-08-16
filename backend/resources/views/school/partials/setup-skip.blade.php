{{--
  Lets a school pass on a step it does not use (2026-08-16). Recorded
  explicitly rather than inferred from empty data: "we have not done
  this yet" and "we do not use this" look identical in the database, and
  only the school knows which is true. Reversible -- the skipped step
  offers to restore itself.
--}}
<form method="POST" action="{{ route('school.setup.skip', ['school' => $school->slug]) }}" style="margin-top:.8rem">
  @csrf
  <input type="hidden" name="step" value="{{ $step }}" />
  <button class="btn btn-ghost btn-sm" type="submit">Skip this step</button>
  <span class="muted" style="margin-left:.5rem;font-size:.82rem">You can come back to it any time.</span>
</form>
