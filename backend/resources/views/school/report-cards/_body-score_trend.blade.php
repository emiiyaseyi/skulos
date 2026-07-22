{{-- Score Trend Chart (user request 2026-07-19: "add option to add text, charts etc to the report card"). This student's own overall average, one point per term, from ReportCardService::forStudent()/sample()'s "trend" key. Print safe inline SVG (explicit colors, not theme-following CSS vars, since a printed/PDF report card must read correctly regardless of the viewer's on-screen theme), line or bar depending on the school's chart_style setting (user request 2026-07-22: "apply to all areas that the chart is"), the same zoomed-axis line vs zero-anchored bar rule the shared dashboard trend widget follows. --}}
@if(! empty($data['trend']) && count($data['trend']) > 1)

  @php
    $values = array_values($data['trend']);
    $labels = array_keys($data['trend']);
    $count = count($values);
    $chartW = 500;
    $chartH = 90;
    $chartStyle = \App\Models\Tenant\Setting::grab('chart_style', config('skulos.default_chart_style', 'line'));
  @endphp

  <div class="rc-block">
    <h3>Score Trend</h3>
    @if($chartStyle === 'bar')

      @php
        $max = max(1, max($values));
        $gap = 12;
        $barW = $count > 0 ? max(16, ($chartW - $gap * ($count - 1)) / $count) : 0;
      @endphp
      <svg viewBox="-8 -22 {{ $chartW + 16 }} {{ $chartH + 40 }}" style="width:100%;max-width:32rem;height:auto;overflow:visible" role="img" aria-label="Overall average trend across recent terms">
        <line x1="0" y1="{{ $chartH }}" x2="{{ $chartW }}" y2="{{ $chartH }}" stroke="#ddd" stroke-width="1" />
        @foreach($values as $i => $v)

          @php
            $barH = max(2, ($v / $max) * $chartH);
          @endphp
          <rect x="{{ round($i * ($barW + $gap), 1) }}" y="{{ round($chartH - $barH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($barH, 1) }}" rx="3" fill="var(--accent)" />
          <text x="{{ round($i * ($barW + $gap) + $barW / 2, 1) }}" y="{{ round($chartH - $barH - 6, 1) }}" text-anchor="middle" font-size="11" font-weight="700" fill="#1a1a1a">{{ $v }}%</text>
          <text x="{{ round($i * ($barW + $gap) + $barW / 2, 1) }}" y="{{ $chartH + 18 }}" text-anchor="middle" font-size="10" fill="#777">{{ $labels[$i] }}</text>
        @endforeach
      </svg>
    @else

      @php
        $min = min($values);
        $max = max($values);
        $pad = max(3, ($max - $min) * 0.35);
        $yLow = max(0, $min - $pad);
        $yHigh = min(100, $max + $pad);
        $yRange = max(0.01, $yHigh - $yLow);
        $stepX = $count > 1 ? $chartW / ($count - 1) : 0;
        $points = collect($values)->values()->map(fn ($v, $i) => [
          'x' => round($count > 1 ? $i * $stepX : $chartW / 2, 1),
          'y' => round($chartH - (($v - $yLow) / $yRange * $chartH), 1),
        ]);
        $polyline = $points->map(fn ($p) => "{$p['x']},{$p['y']}")->implode(' ');
      @endphp
      <svg viewBox="-8 -22 {{ $chartW + 16 }} {{ $chartH + 40 }}" style="width:100%;max-width:32rem;height:auto;overflow:visible" role="img" aria-label="Overall average trend across recent terms">
        <polyline points="{{ $polyline }}" fill="none" stroke="var(--accent)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
        @foreach($points as $i => $p)
          <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4" fill="#fff" stroke="var(--accent)" stroke-width="2.5" />
          <text x="{{ $p['x'] }}" y="{{ $p['y'] - 12 }}" text-anchor="middle" font-size="11" font-weight="700" fill="#1a1a1a">{{ $values[$i] }}%</text>
          <text x="{{ $p['x'] }}" y="{{ $chartH + 18 }}" text-anchor="middle" font-size="10" fill="#777">{{ $labels[$i] }}</text>
        @endforeach
      </svg>
    @endif
  </div>
@endif
