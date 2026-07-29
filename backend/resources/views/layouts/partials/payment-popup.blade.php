{{--
  Payment countdown/reminder pop-up (user request 2026-07-25: "let the
  admin configure a payment countdown date to parents who will see how
  many days left to payments, if it has past, it keeps showing as over
  due, 15 days (as set by the admin) it shows daily on the parent login
  as pop up reminder to pay, parent click to confirm payment has been
  made or not"). Server side ($paymentPopup, computed once in layouts/
  app.blade.php) decides whether today is even in the window at all, and
  the real amount still owed, never a fabricated countdown or balance;
  client side (localStorage, keyed by school + date, exactly the same
  pattern the subscription popup above already uses) decides whether it
  has already been shown once today. "Pay now" takes the parent straight
  to Submit Payment; "I'll pay later" is the confirmation this has NOT
  been paid yet, dismissing only for today, matching how a parent who
  genuinely has already paid would instead see nothing at all, since
  nonOptionalOutstandingPooled() only ever returns a positive amount
  when a real balance remains.
--}}
@if($paymentPopup)
<div class="pay-scrim" id="payPopupScrim" role="alertdialog" aria-modal="true" aria-labelledby="payPopupTitle">
  <div class="pay-card">
    <h3 id="payPopupTitle">
      @if($paymentPopup['daysUntilDue'] < 0)
        Fees payment overdue
      @elseif($paymentPopup['daysUntilDue'] === 0)
        Fees payment due today
      @else
        Fees payment due in {{ $paymentPopup['daysUntilDue'] }} day(s)
      @endif
    </h3>
    <p class="muted">
      Due {{ $paymentPopup['dueDate'] }}. Amount outstanding: ₦{{ number_format($paymentPopup['amountDue'], 2) }}.
    </p>
    <div class="pay-actions">
      <button class="btn btn-ghost btn-sm" type="button" id="payPopupDismiss">I'll pay later</button>
      <a class="btn btn-primary btn-sm" id="payPopupPayNow" href="{{ route('school.finance.payments.create', ['school' => $school->slug]) }}">Pay now</a>
    </div>
  </div>
</div>
<style>
  .pay-scrim{position:fixed;inset:0;background:rgba(8,12,20,.6);z-index:205;display:none;place-items:center;padding:1.25rem}
  .pay-scrim.show{display:grid}
  .pay-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.8rem;box-shadow:var(--shadow);text-align:center}
  .pay-card h3{font-size:1rem;font-weight:800;margin-bottom:.4rem}
  .pay-actions{display:flex;gap:.6rem;margin-top:1.3rem}
  .pay-actions .btn{flex:1}
</style>
<script>
(function(){
  var scrim = document.getElementById('payPopupScrim');
  var dismissBtn = document.getElementById('payPopupDismiss');
  if (!scrim) return;
  // Shows again on every fresh login, not just once per calendar day
  // (user directive 2026-07-27: "the payment pop up ought to be on
  // every login for the parents until the complete payment"). Session
  // storage rather than local storage: dismissing hides it for the
  // rest of THIS browser session (so it is not shown again on every
  // single page navigation while the parent is actively looking around
  // the portal), but a real balance still owed means it shows again the
  // next time they sign in, indefinitely, since the server side
  // condition (a real outstanding balance inside the reminder window)
  // is the only thing that ever stops it, never a client side dismissal
  // that outlives the session.
  //
  // The key includes the real Laravel session id (real bug found live
  // 2026-07-28, reported twice: "still not seeing the due balance pop
  // up upon login again on every sign in attempt"), not just the
  // school slug: sessionStorage is scoped to the browser TAB, not to
  // Laravel's own auth session, so logging out and back in in the SAME
  // tab (a real, common thing to do while testing, or on a shared
  // family device) previously left the OLD dismissal flag in place,
  // incorrectly suppressing the popup on a login that should count as
  // fresh. LoginController::login() calls session()->regenerate() on
  // every sign in, so the session id genuinely changes on every fresh
  // login even for the same user in the same tab, giving this key the
  // "resets on login, stable within one login" behaviour the feature
  // actually needs.
  var key = 'skulos-pay-popup-{{ $school->slug }}-{{ session()->getId() }}';
  try {
    if (sessionStorage.getItem(key)) return;
  } catch(e) {}
  scrim.classList.add('show');
  function dismiss(){
    scrim.classList.remove('show');
    try { sessionStorage.setItem(key, '1'); } catch(e) {}
    closeFocus?.();
  }
  var closeFocus = window.SkulosDialog?.open(scrim, {autofocus: dismissBtn, onEscape: dismiss});
  dismissBtn?.addEventListener('click', dismiss);
  // "Pay now" leaves this page rather than dismissing in place, but the
  // popup still visibly hung there for the moment before navigation
  // completed (user report 2026-07-27: "when on click on pay now, it
  // take user to the payment page but the pop up still does not
  // close"), unlike "I'll pay later" which hides immediately. Same
  // dismiss() call, letting the click's own default navigation proceed
  // right after.
  document.getElementById('payPopupPayNow')?.addEventListener('click', dismiss);
})();
</script>
@endif
