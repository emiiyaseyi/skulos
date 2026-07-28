{{-- Subject Breakdown Chart (user request 2026-07-19: "add option to add text, charts etc to the report card"). Each subject's this-term total out of 100, visualizing the same scores table already on the card. Print safe inline SVG, same reasoning as _body-score_trend.blade.php. Line or bar depending on the school's chart_style setting (user directive 2026-07-28: even though a Subject Breakdown compares discrete, unordered subjects rather than a genuine time trend, the school can still choose either style for it, same as every other chart). --}}
@if(! empty($data['subjects']))

  @php
    $bars = collect($data['subjects'])->map(fn ($s) => ['name' => $s['name'], 'total' => (float) $s['total']])->values();
    $count = $bars->count();
    $chartW = 500;
    $chartH = 160;
    $chartStyle = \App\Models\Tenant\Setting::grab('chart_style', config('skulos.default_chart_style', 'line'));
  @endphp

  <div class="rc-block">
    <h3>Subject Breakdown</h3>
    @if($chartStyle === 'bar')

      @php
        $gap = 14;
        $barW = $count > 0 ? max(18, ($chartW - $gap * ($count - 1)) / $count) : 0;
      @endphp
      <svg viewBox="-8 -10 {{ $chartW + 16 }} {{ $chartH + 56 }}" style="width:100%;max-width:32rem;height:auto;overflow:visible" role="img" aria-label="This term's score per subject">
        <line x1="0" y1="{{ $chartH }}" x2="{{ $chartW }}" y2="{{ $chartH }}" stroke="#ddd" stroke-width="1" />
        @foreach($bars as $i => $bar)
          <rect x="{{ round($i * ($barW + $gap), 1) }}" y="{{ round($chartH - max(2, ($bar['total'] / 100) * $chartH), 1) }}" width="{{ round($barW, 1) }}" height="{{ round(max(2, ($bar['total'] / 100) * $chartH), 1) }}" rx="3" fill="var(--accent)" />
          <text x="{{ round($i * ($barW + $gap) + $barW / 2, 1) }}" y="{{ round($chartH - max(2, ($bar['total'] / 100) * $chartH) - 6, 1) }}" text-anchor="middle" font-size="11" font-weight="700" fill="#1a1a1a">{{ round($bar['total']) }}</text>
          <text x="{{ round($i * ($barW + $gap) + $barW / 2, 1) }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="9.5" fill="#777">{{ \Illuminate\Support\Str::limit($bar['name'], 10, '') }}</text>
        @endforeach
      </svg>
    @else

      @php
        $stepX = $count > 1 ? $chartW / ($count - 1) : 0;
        $points = $bars->values()->map(fn ($bar, $i) => [
          'x' => round($count > 1 ? $i * $stepX : $chartW / 2, 1),
          'y' => round($chartH - max(2, ($bar['total'] / 100) * $chartH), 1),
          'name' => $bar['name'],
          'total' => $bar['total'],
        ]);
        $polyline = $points->map(fn ($p) => "{$p['x']},{$p['y']}")->implode(' ');
      @endphp
      <svg viewBox="-8 -10 {{ $chartW + 16 }} {{ $chartH + 56 }}" style="width:100%;max-width:32rem;height:auto;overflow:visible" role="img" aria-label="This term's score per subject">
        <line x1="0" y1="{{ $chartH }}" x2="{{ $chartW }}" y2="{{ $chartH }}" stroke="#ddd" stroke-width="1" />
        <polyline points="{{ $polyline }}" fill="none" stroke="var(--accent)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
        @foreach($points as $p)
          <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4" fill="#fff" stroke="var(--accent)" stroke-width="2.5" />
          <text x="{{ $p['x'] }}" y="{{ $p['y'] - 12 }}" text-anchor="middle" font-size="11" font-weight="700" fill="#1a1a1a">{{ round($p['total']) }}</text>
          <text x="{{ $p['x'] }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="9.5" fill="#777">{{ \Illuminate\Support\Str::limit($p['name'], 10, '') }}</text>
        @endforeach
      </svg>
    @endif
  </div>
@endif
