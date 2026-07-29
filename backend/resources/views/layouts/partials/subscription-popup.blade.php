{{--
  Subscription countdown pop-up (user request 2026-07-17: "the pop for
  the payment renewal pops up on the final 21 days to the due date
  daily"). Server side ($subscriptionPopup, computed once in
  layouts/app.blade.php) decides WHETHER today is even in the window at
  all (role check + real days-until-due, never a fabricated countdown);
  client side (localStorage, keyed by school + date) decides whether it
  has already been shown once today, so it does not reappear on every
  single page navigation within the same day, only once per day per
  school per browser.
--}}
@if($subscriptionPopup)
<div class="sub-scrim" id="subPopupScrim" role="alertdialog" aria-modal="true" aria-labelledby="subPopupTitle">
  <div class="sub-card">
    <h3 id="subPopupTitle">
      @if($subscriptionPopup['daysUntilDue'] < 0)
        Subscription payment overdue
      @elseif($subscriptionPopup['daysUntilDue'] === 0)
        Subscription payment due today
      @else
        Subscription payment due in {{ $subscriptionPopup['daysUntilDue'] }} day(s)
      @endif
    </h3>
    <p class="muted">
      @if($subscriptionPopup['dueDate'])Due {{ $subscriptionPopup['dueDate'] }}.@endif
      @if($subscriptionPopup['amountDue'] !== null) Amount due: ₦{{ number_format($subscriptionPopup['amountDue'], 2) }}.@endif
    </p>
    <div class="sub-actions">
      <button class="btn btn-ghost btn-sm" type="button" id="subPopupDismiss">Remind me tomorrow</button>
      <a class="btn btn-primary btn-sm" href="{{ route('school.subscription.index', ['school' => $school->slug]) }}">Go to Subscription</a>
    </div>
  </div>
</div>
<style>
  .sub-scrim{position:fixed;inset:0;background:rgba(8,12,20,.6);z-index:205;display:none;place-items:center;padding:1.25rem}
  .sub-scrim.show{display:grid}
  .sub-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.8rem;box-shadow:var(--shadow);text-align:center}
  .sub-card h3{font-size:1rem;font-weight:800;margin-bottom:.4rem}
  .sub-actions{display:flex;gap:.6rem;margin-top:1.3rem}
  .sub-actions .btn{flex:1}
</style>
<script>
(function(){
  var scrim = document.getElementById('subPopupScrim');
  var dismissBtn = document.getElementById('subPopupDismiss');
  if (!scrim) return;
  var key = 'skulos-sub-popup-{{ $school->slug }}-' + new Date().toISOString().slice(0, 10);
  try {
    if (localStorage.getItem(key)) return;
  } catch(e) {}
  scrim.classList.add('show');
  function dismiss(){
    scrim.classList.remove('show');
    try { localStorage.setItem(key, '1'); } catch(e) {}
    closeFocus?.();
  }
  // Focus trap + Escape-to-dismiss (audit finding H-6, 2026-07-17): this
  // popup appears unprompted on page load, so a keyboard/screen-reader
  // user's focus previously stayed on the hidden page behind it with no
  // way to reach or dismiss it without a mouse.
  var closeFocus = window.SkulosDialog?.open(scrim, {autofocus: dismissBtn, onEscape: dismiss});
  dismissBtn?.addEventListener('click', dismiss);
})();
</script>
@endif
