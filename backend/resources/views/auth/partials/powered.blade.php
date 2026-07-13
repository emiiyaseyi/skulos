@php($support = app(\App\Services\Platform\SupportContactService::class)->effective())
<div class="powered">Powered by <span class="skword">SK<em>U</em>LOS</span></div>
<div class="support-line">
  Need help? <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>
  &middot; <a href="tel:{{ str_replace(' ', '', $support['phone']) }}">{{ $support['phone'] }}</a>
</div>
