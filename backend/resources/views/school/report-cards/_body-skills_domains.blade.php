{{--
  Psychomotor Domain (one list) beside Affective Domain (two columns of
  trait and value), user directive 2026-09-24, from the school's own Basic
  1 to 5 report card. Each trait's domain is set on the trait itself
  (BehaviouralTrait.domain); a trait with no domain is not shown here (it
  still shows in the plain Behavioural Traits section). Same
  class-teacher-entered values that section shows, gated on the same toggle.
--}}
@php
  $domTraits = collect($data['traits'] ?? []);
  $psy = $domTraits->where('domain', 'psychomotor')->values();
  $aff = $domTraits->where('domain', 'affective')->values();
  $affHalf = (int) ceil($aff->count() / 2);
  $affRows = $affHalf > 0 ? $affHalf : 0;
@endphp
@if($show('behavioural_traits') && ($psy->isNotEmpty() || $aff->isNotEmpty()))
  <div class="rc-block rc-domains">
    @if($psy->isNotEmpty())
      <table class="rc-bordered-table rc-psychomotor">
        <thead><tr><th colspan="2">Psychomotor Domain</th></tr></thead>
        <tbody>
          @foreach($psy as $t)
            <tr><td class="rc-trait">{{ $t['name'] }}</td><td class="num">{{ $t['value'] ?? '' }}</td></tr>
          @endforeach
        </tbody>
      </table>
    @endif
    @if($aff->isNotEmpty())
      <table class="rc-bordered-table rc-affective">
        <thead><tr><th colspan="4">Affective Domain</th></tr></thead>
        <tbody>
          @for($i = 0; $i < $affRows; $i++)
            @php($left = $aff[$i] ?? null)
            @php($right = $aff[$i + $affRows] ?? null)
            <tr>
              <td class="rc-trait">{{ $left['name'] ?? '' }}</td><td class="num">{{ $left['value'] ?? '' }}</td>
              <td class="rc-trait">{{ $right['name'] ?? '' }}</td><td class="num">{{ $right['value'] ?? '' }}</td>
            </tr>
          @endfor
        </tbody>
      </table>
    @endif
  </div>
@endif
