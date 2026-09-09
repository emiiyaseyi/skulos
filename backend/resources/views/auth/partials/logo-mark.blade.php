{{--
  The school's login-page mark(s). Expects $b (School::branding()), $initial,
  $markClass ('lp-logo' or 'bp-logo'), and $sectionLogos -- an array of
  ['logo' => path|null, 'label' => string], built by LoginController when
  the school has more than one distinct section logo AND has turned on
  "Show every section's logo on the login page" (user request 2026-09-09).
  Falls back to the school's single default mark otherwise, unchanged from
  before this feature existed.
--}}
@if(! empty($sectionLogos) && count($sectionLogos) > 1)
  <div class="lp-logo-lockup">
    @foreach($sectionLogos as $sl)
      <div class="{{ $markClass }} lp-logo-sm" title="{{ $sl['label'] }}">
        @if($sl['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($sl['logo']) }}" alt="{{ $sl['label'] }}">@else{{ $initial }}@endif
      </div>
    @endforeach
  </div>
@else
  <div class="{{ $markClass }}">
    @if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif
  </div>
@endif
