@if(! empty($items))
  <div class="card rp-card">
    <h3 style="margin-bottom:.3rem">{{ $title ?? 'Students at risk' }}</h3>
    <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">{{ $subtitle ?? 'Low attendance or low average this term, an early flag, not a formal report.' }}</p>
    <ul class="rp-list">
      @foreach($items as $item)
        <li><span><strong>{{ $item['name'] }}</strong> <span class="muted">{{ $item['class'] }}</span></span><span class="muted">{{ $item['reason'] }}</span></li>
      @endforeach
    </ul>
  </div>
@endif
