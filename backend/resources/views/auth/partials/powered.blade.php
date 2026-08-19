@php($support = app(\App\Services\Platform\SupportContactService::class)->effective())
{{--
  2026-08-19, direct instruction: "on each login page, make the SKULOS
  logo clickable which takes one to the marketing homepage." This one
  partial is included from every login layout (split/cover/showcase/
  centered) and both the school-branded and bare platform-admin sign in
  screens, so fixing it here covers all of them at once.
--}}
@php($marketingUrl = \App\Support\MarketingUrl::home())
<div class="powered">Powered by <a href="{{ $marketingUrl }}" class="skword" style="text-decoration:none">SK<em>U</em>LOS</a></div>
<div class="support-line">
  Need help? <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>
  &middot; <a href="tel:{{ str_replace(' ', '', $support['phone']) }}">{{ $support['phone'] }}</a>
</div>
