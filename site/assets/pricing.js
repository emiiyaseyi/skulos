/*
 * Marketing CMS wiring (2026-07-21, tracker section 13): fetches the live
 * plan catalog from the Laravel backend's public /plans endpoint
 * (PlanCatalogController) and uses it to update price and the "See all
 * features" checklist on the pricing grid, wherever one exists on the
 * current page (pricing.html and index.html both have one, sharing this
 * one script instead of each carrying its own copy).
 *
 * Deliberately fails closed: if the fetch errors for any reason (backend
 * not deployed yet, offline, CORS not yet configured for this origin),
 * the page is left exactly as its own static HTML/embedded JSON already
 * has it, hand maintained to match the last known real plan catalog, so
 * the marketing site never looks broken because of the backend's state.
 */
(function () {
  var API_BASE = (function () {
    var host = location.hostname;
    if (host === 'localhost' || host === '127.0.0.1') return 'http://localhost:8000';

    // TODO: point this at the real production Laravel host once it is
    // actually deployed (see DEVELOPMENT_TRACKER.md, "live production
    // deployment" under "What's actually left to build" — no live deploy
    // has happened yet as of this file being written). Left unset rather
    // than a guessed domain: the fetch below will simply fail until this
    // is filled in, and the page falls back to its static pricing exactly
    // as it does today, never showing broken or fabricated data.
    return '';
  })();

  function formatNaira(amount) {
    return '₦' + Number(amount).toLocaleString('en-NG', { maximumFractionDigits: 0 });
  }

  function applyLivePricing(catalog) {
    (catalog.plans || []).forEach(function (plan) {
      var button = document.querySelector('.tier-more[data-plan="' + plan.slug + '"]');
      var tier = button ? button.closest('.tier') : null;
      if (!tier) return;

      var priceEl = tier.querySelector('.price b');
      if (priceEl) priceEl.textContent = formatNaira(plan.price_per_student_per_term);

      if (plan.is_purchasable) {
        tier.classList.remove('is-soon');
        var flag = tier.querySelector('.tier-flag-soon');
        if (flag) flag.remove();
        var cta = tier.querySelector('a.btn-ghost, a.btn-gold');
        if (cta && /waitlist/i.test(cta.textContent)) {
          cta.textContent = 'Start free term';
          cta.setAttribute('href', 'trial.html');
        }
      } else {
        tier.classList.add('is-soon');
      }
    });
  }

  function buildFeatureMap(catalog) {
    var map = {};
    (catalog.plans || []).forEach(function (plan) {
      map[plan.slug] = (plan.feature_groups || []).map(function (group) {
        return [group.label, group.features];
      });
    });

    return map;
  }

  // Wires the "See all features" modal once, reading whatever
  // getFeatures() currently returns at the moment a button is clicked —
  // the static embedded fallback until (and unless) the live fetch below
  // resolves, the live data from then on.
  function initFeatureModal(getFeatures) {
    var modal = document.getElementById('featureModal');
    if (!modal) return;

    var titleEl = modal.querySelector('#featureModalTitle');
    var bodyEl = modal.querySelector('.feature-modal-body');
    var lastFocused = null;

    function render(planKey, planLabel) {
      var groups = getFeatures()[planKey] || [];
      var total = groups.reduce(function (sum, g) { return sum + g[1].length; }, 0);
      titleEl.textContent = planLabel + ': ' + total + ' features';
      bodyEl.innerHTML = groups.map(function (group) {
        var items = group[1].map(function (f) { return '<li>' + f + '</li>'; }).join('');

        return '<div class="feature-group"><h4>' + group[0] + '</h4><ul>' + items + '</ul></div>';
      }).join('');
    }

    function open(planKey, planLabel) {
      render(planKey, planLabel);
      lastFocused = document.activeElement;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      modal.querySelector('.feature-modal-close').focus();
      document.body.style.overflow = 'hidden';
    }

    function close() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocused) lastFocused.focus();
    }

    document.querySelectorAll('.tier-more').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var plan = btn.getAttribute('data-plan');
        var label = btn.closest('.tier').querySelector('h3').textContent;
        open(plan, label);
      });
    });

    modal.querySelectorAll('[data-close]').forEach(function (el) {
      el.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });
  }

  function init() {
    var dataEl = document.getElementById('planFeaturesData');
    var staticFeatures = dataEl ? JSON.parse(dataEl.textContent) : {};
    var liveFeatures = null;

    initFeatureModal(function () { return liveFeatures || staticFeatures; });

    if (!API_BASE) return;

    fetch(API_BASE + '/plans', { headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('bad status'); return r.json(); })
      .then(function (catalog) {
        applyLivePricing(catalog);
        liveFeatures = buildFeatureMap(catalog);
      })
      .catch(function () {
        // Backend unreachable or not yet deployed: leave the static
        // markup and fallback feature data exactly as they already are.
      });
  }

  if (document.getElementById('pricing')) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }
})();
