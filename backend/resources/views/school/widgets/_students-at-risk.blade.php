@if(! empty($items))
  @once
    <style>
      .rp-card{margin-bottom:1.5rem}
      .rp-list{list-style:none;display:flex;flex-direction:column;gap:.5rem;margin:0;padding:0}
      .rp-list li{display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.5rem .7rem;background:var(--bg);border-radius:8px;font-size:.88rem}
      .rp-list li .rp-text{display:flex;flex-direction:column;gap:.1rem}
      .rp-filter-bar{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:.7rem}
      .rp-filter-bar input[type="search"]{max-width:14rem}
    </style>
  @endonce
  <div class="card rp-card">
    <h3 style="margin-bottom:.3rem">{{ $title ?? 'Students at risk' }}</h3>
    <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">{{ $subtitle ?? 'Low attendance or low average this term, an early flag, not a formal report.' }}</p>

    @if($filterable ?? false)
      @php($widgetId = 'atRisk'.\Illuminate\Support\Str::random(6))
      <div class="rp-filter-bar">
        <input type="search" id="filter-{{ $widgetId }}" placeholder="Filter by name or class" aria-label="Filter by name or class" />
        <select id="sort-{{ $widgetId }}" aria-label="Sort">
          <option value="reason">Sort: Lowest first</option>
          <option value="name">Sort: Name A to Z</option>
          <option value="class">Sort: Class</option>
        </select>
      </div>
    @endif

    <ul class="rp-list" id="list-{{ $widgetId ?? '' }}">
      @foreach($items as $item)
        <li data-name="{{ strtolower($item['name']) }}" data-class="{{ strtolower($item['class'] ?? '') }}" data-reason="{{ $loop->index }}">
          <span class="rp-text"><strong>{{ $item['name'] }}</strong><span class="muted">{{ $item['class'] }}</span></span>
          <span class="muted">{{ $item['reason'] }}</span>
        </li>
      @endforeach
    </ul>
  </div>

  @if($filterable ?? false)
    <script>
    (function(){
      var list = document.getElementById('list-{{ $widgetId }}');
      var filterInput = document.getElementById('filter-{{ $widgetId }}');
      var sortSelect = document.getElementById('sort-{{ $widgetId }}');
      if (! list) return;

      filterInput.addEventListener('input', function(){
        var q = this.value.trim().toLowerCase();
        list.querySelectorAll('li').forEach(function(li){
          var matches = li.dataset.name.includes(q) || li.dataset.class.includes(q);
          li.style.display = matches ? '' : 'none';
        });
      });

      sortSelect.addEventListener('change', function(){
        var items = Array.prototype.slice.call(list.querySelectorAll('li'));
        var key = this.value === 'name' ? 'name' : (this.value === 'class' ? 'class' : 'reason');
        items.sort(function(a, b){
          if (key === 'reason') { return (+a.dataset.reason) - (+b.dataset.reason); }
          return a.dataset[key].localeCompare(b.dataset[key]);
        });
        items.forEach(function(li){ list.appendChild(li); });
      });
    })();
    </script>
  @endif
@endif
