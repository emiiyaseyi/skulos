@if(! empty($items))
  @once
    <style>
      .rp-card{margin-bottom:1.5rem}
      .rp-list{list-style:none;display:flex;flex-direction:column;gap:.5rem;margin:0;padding:0}
      .rp-list li{display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.5rem .7rem;background:var(--bg);border-radius:8px;font-size:.88rem}
      .rp-list li .rp-text{display:flex;flex-direction:column;gap:.1rem}
      .rp-filter-bar{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:.7rem}
      .rp-filter-bar input[type="search"]{max-width:14rem}
      .rp-pager{display:flex;justify-content:space-between;align-items:center;gap:.6rem;margin-top:.7rem;flex-wrap:wrap}
    </style>
  @endonce
  @php($widgetId = 'atRisk'.\Illuminate\Support\Str::random(6))
  <div class="card rp-card">
    <h3 style="margin-bottom:.3rem">{{ $title ?? 'Students at risk' }}</h3>
    <p class="muted" style="margin-bottom:.8rem">{{ $subtitle ?? 'Low attendance or low average this term, an early flag, not a formal report.' }}</p>

    @if($filterable ?? false)
      <div class="rp-filter-bar">
        <input type="search" id="filter-{{ $widgetId }}" placeholder="Filter by name or class" aria-label="Filter by name or class" />
        <select id="sort-{{ $widgetId }}" aria-label="Sort">
          <option value="reason">Sort: Lowest first</option>
          <option value="name">Sort: Name A to Z</option>
          <option value="class">Sort: Class</option>
        </select>
      </div>
    @endif

    <ul class="rp-list" id="list-{{ $widgetId }}">
      @foreach($items as $item)
        <li data-name="{{ strtolower($item['name']) }}" data-class="{{ strtolower($item['class'] ?? '') }}" data-reason="{{ $loop->index }}">
          <span class="rp-text"><strong>{{ $item['name'] }}</strong><span class="muted">{{ $item['class'] }}</span></span>
          <span class="muted">{{ $item['reason'] }}</span>
        </li>
      @endforeach
    </ul>

    {{-- User request 2026-07-22: "Add pagination to 'Performance at risk' if data there is more than 10. So we should have 10 per page." Client side, matching this widget's existing all-client-side filter/sort, hidden entirely when there is nothing to page through. --}}
    @if(count($items) > 10)
      <div class="rp-pager" id="pager-{{ $widgetId }}">
        <button type="button" class="btn btn-ghost btn-sm" id="prev-{{ $widgetId }}">Previous</button>
        <span class="muted" id="pageinfo-{{ $widgetId }}"></span>
        <button type="button" class="btn btn-ghost btn-sm" id="next-{{ $widgetId }}">Next</button>
      </div>
    @endif
  </div>

  <script>
  (function(){
    var widgetId = {{ Js::from($widgetId) }};
    var perPage = 10;
    var page = 1;
    var list = document.getElementById('list-' + widgetId);
    var pager = document.getElementById('pager-' + widgetId);
    var pageInfo = document.getElementById('pageinfo-' + widgetId);
    var prevBtn = document.getElementById('prev-' + widgetId);
    var nextBtn = document.getElementById('next-' + widgetId);
    if (! list) return;

    function visibleItems() {
      return Array.prototype.filter.call(list.querySelectorAll('li'), function(li){ return li.dataset.filteredOut !== '1'; });
    }

    function paginate() {
      var items = visibleItems();
      var totalPages = Math.max(1, Math.ceil(items.length / perPage));
      if (page > totalPages) page = totalPages;
      items.forEach(function(li, i){
        var onPage = Math.floor(i / perPage) + 1;
        li.style.display = (onPage === page) ? '' : 'none';
      });
      if (pager) {
        pager.style.display = items.length > perPage ? '' : 'none';
        if (pageInfo) pageInfo.textContent = 'Page ' + page + ' of ' + totalPages;
        if (prevBtn) prevBtn.disabled = page <= 1;
        if (nextBtn) nextBtn.disabled = page >= totalPages;
      }
    }

    prevBtn && prevBtn.addEventListener('click', function(){ page = Math.max(1, page - 1); paginate(); });
    nextBtn && nextBtn.addEventListener('click', function(){ page = page + 1; paginate(); });

    @if($filterable ?? false)
      var filterInput = document.getElementById('filter-{{ $widgetId }}');
      var sortSelect = document.getElementById('sort-{{ $widgetId }}');

      filterInput.addEventListener('input', function(){
        var q = this.value.trim().toLowerCase();
        list.querySelectorAll('li').forEach(function(li){
          var matches = li.dataset.name.includes(q) || li.dataset.class.includes(q);
          li.dataset.filteredOut = matches ? '0' : '1';
        });
        page = 1;
        paginate();
      });

      sortSelect.addEventListener('change', function(){
        var items = Array.prototype.slice.call(list.querySelectorAll('li'));
        var key = this.value === 'name' ? 'name' : (this.value === 'class' ? 'class' : 'reason');
        items.sort(function(a, b){
          if (key === 'reason') { return (+a.dataset.reason) - (+b.dataset.reason); }
          return a.dataset[key].localeCompare(b.dataset[key]);
        });
        items.forEach(function(li){ list.appendChild(li); });
        page = 1;
        paginate();
      });
    @endif

    paginate();
  })();
  </script>
@endif
