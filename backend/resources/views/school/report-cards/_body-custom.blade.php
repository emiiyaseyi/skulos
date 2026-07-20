{{-- A school-authored free text block (user request 2026-07-19: "add option to add text... like address etc"). $customBlock is injected by whichever loop resolves the "custom_{id}" body section key. --}}
@if($customBlock)
  <div class="rc-block">
    <h3>{{ $customBlock->label }}</h3>
    <p style="white-space:pre-line">{{ $customBlock->content }}</p>
  </div>
@endif
