{{-- Subject Breakdown Chart (user request 2026-07-19: "add option to add text, charts etc to the report card"). A bar per subject, this term's total out of 100, visualizing the same scores table already on the card. Print safe inline SVG, same reasoning as _body-score_trend.blade.php. --}}
@if(! empty($data['subjects']))

  @php
    $bars = collect($data['subjects'])->map(fn ($s) => ['name' => $s['name'], 'total' => (float) $s['total']])->values();
    $count = $bars->count();
    $chartW = 500;
    $chartH = 160;
    $gap = 14;
    $barW = $count > 0 ? max(18, ($chartW - $gap * ($count - 1)) / $count) : 0;
  @endphp

  <div class="rc-block">
    <h3>Subject Breakdown</h3>
    <svg viewBox="-8 -10 {{ $chartW + 16 }} {{ $chartH + 56 }}" style="width:100%;max-width:32rem;height:auto;overflow:visible" role="img" aria-label="This term's score per subject">
      <line x1="0" y1="{{ $chartH }}" x2="{{ $chartW }}" y2="{{ $chartH }}" stroke="#ddd" stroke-width="1" />
      @foreach($bars as $i => $bar)
        <rect x="{{ round($i * ($barW + $gap), 1) }}" y="{{ round($chartH - max(2, ($bar['total'] / 100) * $chartH), 1) }}" width="{{ round($barW, 1) }}" height="{{ round(max(2, ($bar['total'] / 100) * $chartH), 1) }}" rx="3" fill="var(--accent)" />
        <text x="{{ round($i * ($barW + $gap) + $barW / 2, 1) }}" y="{{ round($chartH - max(2, ($bar['total'] / 100) * $chartH) - 6, 1) }}" text-anchor="middle" font-size="11" font-weight="700" fill="#1a1a1a">{{ round($bar['total']) }}</text>
        <text x="{{ round($i * ($barW + $gap) + $barW / 2, 1) }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="9.5" fill="#777">{{ \Illuminate\Support\Str::limit($bar['name'], 10, '') }}</text>
      @endforeach
    </svg>
  </div>
@endif
