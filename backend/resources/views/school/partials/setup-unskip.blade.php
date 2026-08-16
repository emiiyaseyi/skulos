<form method="POST" action="{{ route('school.setup.skip', ['school' => $school->slug]) }}">
  @csrf
  <input type="hidden" name="step" value="{{ $step }}" />
  <button class="btn btn-ghost btn-sm" type="submit">Set this up after all</button>
</form>
