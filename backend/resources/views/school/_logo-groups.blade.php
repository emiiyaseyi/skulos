{{--
  "Logo for the other sections" builder (user directive 2026-09-23: once the
  first logo is for just some sections, ask for another logo for the other
  sections, and another, then save everything once). Included right after
  the logo scope picker on BOTH Branding and School Profile, INSIDE their
  form so the page's own Save button saves it all. Expects $sections.
  Each group posts as logo_groups[n][file] + logo_groups[n][sections][].
  A section ticked in one place is disabled everywhere else, so no section
  can be given two logos at once.
--}}
@if($sections->count() > 1)
  <div data-logo-groups style="display:none;margin:.2rem 0 .8rem">
    <p class="hint" style="margin-bottom:.4rem">The logo above is for the sections you ticked. Add a logo for the other sections here, and another if you need one. Use Save once at the bottom to save everything.</p>
    <div data-logo-group-list></div>
    <button type="button" class="btn btn-ghost btn-sm" data-logo-group-add>+ Add a logo for other sections</button>
  </div>
  <template data-logo-group-tpl>
    <div data-logo-group style="border:1px solid var(--line);border-radius:10px;padding:.7rem;margin:.5rem 0">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.5rem">
        <strong>Logo for other sections</strong>
        <button type="button" class="btn btn-ghost btn-sm" data-logo-group-remove>Remove</button>
      </div>
      <input type="file" name="logo_groups[__I__][file]" accept="image/*" />
      <div class="section-ticks open" style="margin-top:.5rem">
        @foreach($sections as $s)
          <label><input type="checkbox" name="logo_groups[__I__][sections][]" value="{{ $s->id }}"> {{ $s->name }}</label>
        @endforeach
      </div>
    </div>
  </template>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var wrap = document.querySelector('[data-logo-groups]');
      if (!wrap) return;
      var form = wrap.closest('form');
      var list = wrap.querySelector('[data-logo-group-list]');
      var tpl = document.querySelector('[data-logo-group-tpl]');
      var addBtn = wrap.querySelector('[data-logo-group-add]');
      var mainBoxes = form.querySelectorAll('input[name="logo_sections[]"]');
      var counter = 0;

      function boxes() {
        return Array.prototype.slice.call(form.querySelectorAll('input[name="logo_sections[]"], input[name^="logo_groups"][type="checkbox"]'));
      }
      function taken() {
        var t = {};
        boxes().forEach(function (b) { if (b.checked) t[b.value] = b; });
        return t;
      }
      function addGroup() {
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__I__/g, counter++);
        list.appendChild(holder.firstElementChild);
        refresh(false);
      }
      function refresh(allowAuto) {
        var t = taken();
        boxes().forEach(function (b) { b.disabled = !!t[b.value] && t[b.value] !== b; });
        var left = mainBoxes.length - Object.keys(t).length;
        addBtn.style.display = left > 0 ? '' : 'none';
        // Ask for the next logo as soon as the last one has sections and some are still unassigned.
        if (allowAuto && left > 0) {
          var groups = list.querySelectorAll('[data-logo-group]');
          var last = groups[groups.length - 1];
          var lastHasTick = last && last.querySelector('input[type="checkbox"]:checked');
          if (!last || lastHasTick) addGroup();
        }
      }
      function syncScope() {
        var picked = form.querySelector('input[name="logo_scope"]:checked');
        var on = picked && picked.value === 'sections';
        wrap.style.display = on ? '' : 'none';
        if (on && !list.querySelector('[data-logo-group]')) refresh(true);
      }

      form.addEventListener('change', function (e) {
        var n = e.target.name || '';
        if (n === 'logo_scope') syncScope();
        else if (n === 'logo_sections[]' || n.indexOf('logo_groups') === 0) refresh(true);
      });
      wrap.addEventListener('click', function (e) {
        if (e.target.matches('[data-logo-group-add]')) addGroup();
        if (e.target.matches('[data-logo-group-remove]')) {
          e.target.closest('[data-logo-group]').remove();
          refresh(false);
        }
      });
      syncScope();
    });
  </script>
@endif
