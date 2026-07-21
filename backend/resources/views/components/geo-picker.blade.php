@php
  $lgaVisible = $showLga || (old($lgaName) && $initialLgas->isNotEmpty());
@endphp
<div data-geo-picker id="geo-picker-{{ $countryName }}" style="display:contents">
  <div class="field">
    <label @class(['req' => $countryRequired]) for="{{ $countryName }}">Country</label>
    <select id="{{ $countryName }}" name="{{ $countryName }}" data-geo-role="country" @required($countryRequired)>
      <option value="">Select a country</option>
      @foreach($countries as $country)
        <option value="{{ $country->name }}" data-id="{{ $country->id }}" data-iso2="{{ $country->iso2 }}" @selected(old($countryName, $countryValue) === $country->name)>{{ $country->name }}</option>
      @endforeach
    </select>
    @error($countryName)<div class="err">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label @class(['req' => $stateRequired]) for="{{ $stateName }}">State</label>
    <select id="{{ $stateName }}" name="{{ $stateName }}" data-geo-role="state" @required($stateRequired)>
      <option value="">{{ $initialStates->isNotEmpty() ? 'Select a state' : 'Select a country first' }}</option>
      @foreach($initialStates as $state)
        <option value="{{ $state->name }}" data-id="{{ $state->id }}" @selected(old($stateName, $stateValue) === $state->name)>{{ $state->name }}</option>
      @endforeach
    </select>
    @error($stateName)<div class="err">{{ $message }}</div>@enderror
  </div>

  {{-- Spans the full row on its own (grid-column:1/-1) rather than sharing
       a row with whatever comes after it: LGA only ever appears alongside
       exactly one sibling field (state), so leaving it a plain grid cell
       would either dangle alone in an otherwise empty row or silently
       shift every field after it, depending on the surrounding form's
       field count. This keeps the picker safe to drop into any 2 or
       3 column field grid without the caller having to account for it. --}}
  <div class="field" data-lga-wrapper style="grid-column:1/-1;{{ $lgaVisible ? '' : 'display:none' }}">
    <label @class(['req' => $lgaRequired]) for="{{ $lgaName }}">LGA</label>
    <select id="{{ $lgaName }}" name="{{ $lgaName }}" data-geo-role="lga" @required($lgaRequired) @disabled(!$lgaVisible)>
      <option value="">{{ $initialLgas->isNotEmpty() ? 'Select an LGA' : 'Not applicable' }}</option>
      @foreach($initialLgas as $lga)
        <option value="{{ $lga->name }}" @selected(old($lgaName, $lgaValue) === $lga->name)>{{ $lga->name }}</option>
      @endforeach
    </select>
    @error($lgaName)<div class="err">{{ $message }}</div>@enderror
  </div>
</div>

@once
@push('scripts')
<script>
(function () {
  // Reusable country to state to LGA cascading picker (backend/app/View/Components/GeoPicker.php).
  // Every field on the page marked data-geo-picker wires itself up independently, so this same
  // script works no matter how many times the component is dropped onto one page.
  function clearSelect(select, placeholder) {
    select.innerHTML = '';
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    select.appendChild(opt);
  }

  function fillSelect(select, items, placeholder) {
    clearSelect(select, placeholder);
    items.forEach(function (item) {
      var opt = document.createElement('option');
      opt.value = item.name;
      opt.dataset.id = item.id;
      opt.textContent = item.name;
      select.appendChild(opt);
    });
    // State and LGA start with only a placeholder option, so the
    // searchable-select enhancer (app.blade.php) skips them on initial
    // page load as too short. Once populated from the API they're
    // usually well past that threshold, so re-run the enhancer now;
    // it's a no-op for a select it already enhanced.
    if (window.SkulosSearchable) window.SkulosSearchable.enhanceAll(select.parentNode);
  }

  function setLgaVisible(wrapper, select, visible) {
    wrapper.style.display = visible ? '' : 'none';
    select.disabled = !visible;
    if (!visible) {
      clearSelect(select, 'Not applicable');
    }
  }

  document.querySelectorAll('[data-geo-picker]').forEach(function (root) {
    var countrySelect = root.querySelector('[data-geo-role="country"]');
    var stateSelect = root.querySelector('[data-geo-role="state"]');
    var lgaSelect = root.querySelector('[data-geo-role="lga"]');
    var lgaWrapper = root.querySelector('[data-lga-wrapper]');
    if (!countrySelect || !stateSelect || !lgaSelect || !lgaWrapper) {
      return;
    }

    countrySelect.addEventListener('change', function () {
      var chosen = countrySelect.selectedOptions[0];
      var countryId = chosen ? chosen.dataset.id : '';
      setLgaVisible(lgaWrapper, lgaSelect, false);
      if (!countryId) {
        clearSelect(stateSelect, 'Select a country first');
        return;
      }
      clearSelect(stateSelect, 'Loading...');
      fetch('/geo/countries/' + countryId + '/states', { headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); })
        .then(function (states) {
          fillSelect(stateSelect, states, 'Select a state');
        });
    });

    stateSelect.addEventListener('change', function () {
      var chosenCountry = countrySelect.selectedOptions[0];
      var isNigeria = chosenCountry ? chosenCountry.dataset.iso2 === 'NG' : false;
      var chosenState = stateSelect.selectedOptions[0];
      var stateId = chosenState ? chosenState.dataset.id : '';
      if (!isNigeria || !stateId) {
        setLgaVisible(lgaWrapper, lgaSelect, false);
        return;
      }
      fetch('/geo/states/' + stateId + '/lgas', { headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); })
        .then(function (lgas) {
          if (lgas.length === 0) {
            setLgaVisible(lgaWrapper, lgaSelect, false);
            return;
          }
          setLgaVisible(lgaWrapper, lgaSelect, true);
          fillSelect(lgaSelect, lgas, 'Select an LGA');
        });
    });
  });
})();
</script>
@endpush
@endonce
