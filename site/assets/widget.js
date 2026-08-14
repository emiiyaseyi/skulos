/*
 * SKULOS floating contact widget.
 * Included on every prospective-user page with a deferred script tag that
 * points at assets/widget.js.
 * Contact details live here only (one source of truth, nothing hardcoded
 * into individual pages). On-screen text carries no dashes by design.
 */
(function () {
  var CONTACT = {
    whatsapp: '2348023373302',        // digits only, for wa.me
    whatsappLabel: '+234 802 337 3302',
    email: 'info@skulosapp.com',
    contactUrl: 'contact.html'
  };

  var css = '' +
    '.skx-fab{position:fixed;right:20px;bottom:20px;z-index:900;width:60px;height:60px;border-radius:50%;' +
    'border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;' +
    'background:linear-gradient(135deg,var(--gold-btn-a,#F6C15A),var(--gold-btn-b,#E5901C));' +
    'color:var(--gold-btn-ink,#3A2408);box-shadow:0 12px 30px -8px rgba(0,0,0,.45);transition:transform .2s ease}' +
    '.skx-fab:hover{transform:translateY(-2px) scale(1.03)}' +
    '.skx-fab svg{width:26px;height:26px}' +
    '.skx-fab .skx-close{display:none}' +
    '.skx-open .skx-fab .skx-chat{display:none}' +
    '.skx-open .skx-fab .skx-close{display:block}' +
    '.skx-panel{position:fixed;right:20px;bottom:92px;z-index:900;width:280px;max-width:calc(100vw - 40px);' +
    'border-radius:16px;overflow:hidden;opacity:0;transform:translateY(12px) scale(.98);pointer-events:none;' +
    'transition:opacity .2s ease,transform .2s ease;' +
    'background:var(--night-2,#0b1420);border:1px solid var(--glass-border,rgba(255,255,255,.14));' +
    'box-shadow:0 24px 60px -16px rgba(0,0,0,.5)}' +
    '.skx-open .skx-panel{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}' +
    '.skx-panel-head{padding:16px 18px;border-bottom:1px solid var(--glass-border,rgba(255,255,255,.12))}' +
    '.skx-panel-head b{font-family:var(--font-display,Georgia,serif);font-size:1.05rem;color:var(--paper,#f3f5f8);display:block}' +
    '.skx-panel-head span{font-size:.82rem;color:var(--mist,rgba(243,245,248,.68))}' +
    '.skx-row{display:flex;align-items:center;gap:12px;padding:13px 18px;text-decoration:none;' +
    'color:var(--paper,#f3f5f8);transition:background .15s ease}' +
    '.skx-row:hover{background:var(--glass-strong,rgba(255,255,255,.08))}' +
    '.skx-row+.skx-row{border-top:1px solid var(--glass-border,rgba(255,255,255,.08))}' +
    '.skx-ic{flex:0 0 34px;width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center}' +
    '.skx-ic svg{width:18px;height:18px;color:#fff}' +
    '.skx-row b{font-size:.9rem;font-weight:700;display:block}' +
    '.skx-row span{font-size:.78rem;color:var(--mist,rgba(243,245,248,.66))}' +
    '@media (prefers-reduced-motion: reduce){.skx-fab,.skx-panel{transition:none}}';

  var wa = 'M19.05 4.94A9.82 9.82 0 0 0 12.04 2C6.6 2 2.18 6.42 2.18 11.86c0 1.74.46 3.44 1.32 4.94L2.1 22l5.34-1.4a9.8 9.8 0 0 0 4.6 1.17h.01c5.43 0 9.85-4.42 9.85-9.86a9.8 9.8 0 0 0-2.85-6.97ZM12.05 20.05h-.01a8.2 8.2 0 0 1-4.17-1.14l-.3-.18-3.1.81.83-3.02-.2-.31a8.13 8.13 0 0 1-1.25-4.35c0-4.52 3.68-8.2 8.2-8.2a8.15 8.15 0 0 1 8.19 8.2c0 4.52-3.68 8.19-8.19 8.19Zm4.5-6.13c-.25-.12-1.46-.72-1.69-.8-.22-.09-.39-.12-.55.11-.16.25-.63.8-.77.96-.14.17-.28.19-.53.06-.25-.12-1.04-.38-1.98-1.22-.73-.65-1.23-1.46-1.37-1.71-.14-.25-.02-.38.11-.5.11-.11.25-.28.37-.42.13-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.55-1.34-.76-1.83-.2-.48-.4-.41-.55-.42h-.47c-.16 0-.42.06-.64.31-.22.25-.84.82-.84 2s.86 2.32.98 2.48c.12.17 1.69 2.58 4.1 3.62.57.24 1.02.39 1.37.5.57.18 1.1.16 1.51.1.46-.07 1.46-.6 1.66-1.17.2-.58.2-1.07.14-1.17-.06-.11-.22-.17-.47-.29Z';
  var mail = 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 3.2V18h16V7.2l-8 5-8-5Zm14.4-1.2H5.6L12 9.9 18.4 6Z';
  var form = 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 2 4 4h-4V4ZM8 13h8v2H8v-2Zm0 4h8v2H8v-2Zm0-8h4v2H8V9Z';
  var chat = 'M12 3C6.5 3 2 6.9 2 11.7c0 2.2.95 4.2 2.5 5.72L3.5 22l4.2-1.5c1.3.5 2.75.8 4.3.8 5.5 0 10-3.9 10-8.6S17.5 3 12 3Z';

  function icon(path) { return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="' + path + '"/></svg>'; }

  function build() {
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    var wrap = document.createElement('div');
    wrap.className = 'skx-widget';
    wrap.innerHTML =
      '<div class="skx-panel" id="skxPanel" role="dialog" aria-label="Contact SKULOS">' +
        '<div class="skx-panel-head"><b>Talk to us</b><span>We usually reply within a few hours</span></div>' +
        '<a class="skx-row" href="https://wa.me/' + CONTACT.whatsapp + '" target="_blank" rel="noopener">' +
          '<span class="skx-ic" style="background:linear-gradient(135deg,#25D366,#128C4B)">' + icon(wa) + '</span>' +
          '<span><b>Chat on WhatsApp</b><span>' + CONTACT.whatsappLabel + '</span></span></a>' +
        '<a class="skx-row" href="mailto:' + CONTACT.email + '">' +
          '<span class="skx-ic" style="background:linear-gradient(135deg,#38BDF8,#1B87D6)">' + icon(mail) + '</span>' +
          '<span><b>Send us a mail</b><span>' + CONTACT.email + '</span></span></a>' +
        '<a class="skx-row" href="' + CONTACT.contactUrl + '">' +
          '<span class="skx-ic" style="background:linear-gradient(135deg,#8B7CF6,#6D4FE0)">' + icon(form) + '</span>' +
          '<span><b>Contact form</b><span>Send a detailed message</span></span></a>' +
      '</div>' +
      '<button class="skx-fab" id="skxFab" aria-expanded="false" aria-controls="skxPanel" aria-label="Open contact options">' +
        '<span class="skx-chat">' + icon(chat) + '</span>' +
        '<span class="skx-close">' + icon('M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z') + '</span>' +
      '</button>';
    document.body.appendChild(wrap);

    var fab = wrap.querySelector('#skxFab');
    function setOpen(open) {
      wrap.classList.toggle('skx-open', open);
      fab.setAttribute('aria-expanded', open ? 'true' : 'false');
      fab.setAttribute('aria-label', open ? 'Close contact options' : 'Open contact options');
    }
    fab.addEventListener('click', function () { setOpen(!wrap.classList.contains('skx-open')); });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
