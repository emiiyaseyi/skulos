/* Shared chrome behaviour: theme toggle (persisted, OS-default) + mobile nav drawer. */
(function () {
  var root = document.documentElement;
  var KEY = 'skulos-theme';
  var buttons = [document.getElementById('themeToggle'), document.getElementById('themeToggleDrawer')].filter(Boolean);

  function systemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function current() { return root.getAttribute('data-theme') || systemTheme(); }
  function apply(theme, persist) {
    root.setAttribute('data-theme', theme);
    if (persist) { try { localStorage.setItem(KEY, theme); } catch (e) {} }
    buttons.forEach(function (b) {
      b.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
      b.setAttribute('aria-label', theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme');
    });
  }
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  apply(saved || systemTheme(), false);
  buttons.forEach(function (b) {
    b.addEventListener('click', function () { apply(current() === 'light' ? 'dark' : 'light', true); });
  });
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
      var stored = null; try { stored = localStorage.getItem(KEY); } catch (x) {}
      if (!stored) apply(e.matches ? 'dark' : 'light', false);
    });
  }

  var toggle = document.getElementById('navToggle');
  var drawer = document.getElementById('mobileDrawer');
  if (toggle && drawer) {
    function close() { toggle.setAttribute('aria-expanded', 'false'); drawer.classList.remove('is-open'); }
    function open() { toggle.setAttribute('aria-expanded', 'true'); drawer.classList.add('is-open'); }
    toggle.addEventListener('click', function () {
      toggle.getAttribute('aria-expanded') === 'true' ? close() : open();
    });
    drawer.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', close); });
    document.addEventListener('click', function (e) { if (!drawer.contains(e.target) && !toggle.contains(e.target)) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('resize', function () { if (window.innerWidth > 980) close(); });
  }

  // FAQ accordions, if present on the page.
  document.querySelectorAll('.faq-q').forEach(function (q) {
    q.addEventListener('click', function () {
      var item = q.closest('.faq-item');
      var isOpen = item.classList.contains('open');
      item.classList.toggle('open', !isOpen);
      q.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
    });
  });
})();
