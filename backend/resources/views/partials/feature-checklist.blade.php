{{--
  A reusable nested main-section/sub-section feature checklist
  (2026-08-19), the same visual pattern Billing's own plan editor
  established (user directive 2026-07-16: "CHECK THE BILLING FEATURES
  AGAIN WHERE WE HAVE SO MANY MAIN SECTIONS AND SUB SECTION WITH ALL THE
  FEATURES UNDER EACH SECTION, THAT IS HOW I WANT IT TO APPEAR"), reused
  here rather than a new layout invented from scratch.

  Required props:
    $id        unique DOM id for this instance (a page can have more than one)
    $name      the checkbox input name, e.g. "requested_features"
    $checked   collection/array of already-selected feature keys

  Optional:
    $catalog   defaults to the full config('skulos.plan_feature_catalog')
--}}
@php($catalog = $catalog ?? config('skulos.plan_feature_catalog'))
@php($checked = collect($checked ?? []))

<div id="{{ $id }}">
  <div style="display:flex;gap:.5rem;margin-bottom:.7rem">
    <button type="button" class="btn btn-ghost btn-sm" onclick="skulosFeatureChecklistSetAll('{{ $id }}', true)">Select all</button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="skulosFeatureChecklistSetAll('{{ $id }}', false)">Deselect all</button>
  </div>
  <div>
    @foreach($catalog as $category)
      @php($categoryKeys = array_keys($category['features']))
      @php($categoryChecked = $checked->intersect($categoryKeys)->count())
      <details style="margin-bottom:.4rem" @if($categoryChecked) open @endif>
        <summary style="cursor:pointer;font-size:.82rem;font-weight:600;padding:.3rem 0">
          {{ $category['label'] }}
          <span class="muted" style="font-weight:400">({{ $categoryChecked }}/{{ count($categoryKeys) }})</span>
        </summary>
        <div class="module-grid">
          @foreach($category['features'] as $fKey => $fLabel)
            <label class="module-check">
              <input type="checkbox" name="{{ $name }}[]" value="{{ $fKey }}" @checked($checked->contains($fKey)) />
              {{ $fLabel }}
            </label>
          @endforeach
        </div>
      </details>
    @endforeach
  </div>
</div>

@once
<script>
  function skulosFeatureChecklistSetAll(containerId, on) {
    document.querySelectorAll('#' + containerId + ' input[type="checkbox"]').forEach(function (box) {
      box.checked = on;
    });
  }
</script>
@endonce
