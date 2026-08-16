{{--
  Pick houses or clubs during guided setup (2026-08-16). Two ways in on
  purpose: a school either recognises its own names in the suggested
  list or has entirely different ones, and only offering the defaults
  meant the second kind had to create four rows and then delete four
  others. $kind is 'houses' or 'clubs'; $options is the suggested list.
--}}
<form method="POST" action="{{ route('school.setup.groups', ['school' => $school->slug]) }}">
  @csrf
  <input type="hidden" name="kind" value="{{ $kind }}" />

  @if(count($options))
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem">
      <b style="font-size:.9rem">Suggested</b>
      <button type="button" class="btn btn-ghost btn-sm" data-select-all="{{ $kind }}">Select all</button>
    </div>
    <div class="grid cols-2" style="gap:.35rem;margin-bottom:1rem">
      @foreach($options as $option)
        <label class="module-check">
          <input type="checkbox" name="selected[]" value="{{ $option }}" data-group="{{ $kind }}" />
          {{ $option }}
        </label>
      @endforeach
    </div>
  @endif

  <b style="font-size:.9rem;display:block;margin-bottom:.4rem">Or add your own</b>
  <div class="grid cols-2" style="gap:.5rem;margin-bottom:.9rem">
    @for($i = 0; $i < 4; $i++)
      <input name="custom[]" maxlength="100" placeholder="{{ $i === 0 ? 'Type a '.$label.' name' : 'Optional' }}" />
    @endfor
  </div>

  <button class="btn btn-primary btn-sm" type="submit">Save {{ $label }}s</button>
</form>

@include('school.partials.setup-skip', ['step' => $kind])

<script>
  document.querySelector('[data-select-all="{{ $kind }}"]')?.addEventListener('click', function () {
    var boxes = document.querySelectorAll('input[data-group="{{ $kind }}"]');
    var allOn = Array.prototype.every.call(boxes, function (b) { return b.checked; });
    boxes.forEach(function (b) { b.checked = !allOn; });
    this.textContent = allOn ? 'Select all' : 'Clear all';
  });
</script>
