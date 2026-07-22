{{--
  Shared trend chart, line or bar depending on the school's own
  chart_style setting (user request 2026-07-22: "let's have alterative
  style ot charts to select for this current ones, apply to all areas
  that the chart is"), read here directly rather than threaded through
  every include site, the same self-contained-inline-query pattern
  broadcast-popup.blade.php and notification-bell.blade.php already use.

  A trend LINE's vertical position is relative, not a magnitude encoding,
  so zooming the y-axis to the data's own range is legitimate (real
  differences between close values would otherwise read as visually
  flat). A BAR's length IS a magnitude encoding, so its axis stays
  anchored at zero regardless, never zoomed, the same rule the report
  card's own Subject Breakdown bar chart already follows.

  Expects $values (list<float>, null entries become gaps) and $labels
  (same length, x-axis captions); $suffix (default '%') is appended to
  each point's number label.
--}}
@php
  $suffix = $suffix ?? '%';
  $chartStyle = \App\Models\Tenant\Setting::grab('chart_style', config('skulos.default_chart_style', 'line'));
  $plotted = collect($values)->filter(fn ($v) => $v !== null);
  $count = count($values);
  $chartW = 600;
  $chartH = 100;
@endphp

@if($chartStyle === 'bar')

  @php
    $max = $plotted->isNotEmpty() ? max(1, $plotted->max()) : 100;
    $gap = 14;
    $barW = $count > 0 ? max(18, ($chartW - $gap * ($count - 1)) / $count) : 0;
  @endphp
  <svg viewBox="-8 -22 {{ $chartW + 16 }} {{ $chartH + 46 }}" style="width:100%;height:150px;overflow:visible" role="img" aria-label="{{ $ariaLabel ?? 'Trend chart' }}">
    <line x1="0" y1="{{ $chartH }}" x2="{{ $chartW }}" y2="{{ $chartH }}" stroke="var(--line)" stroke-width="1" />
    @foreach(array_values($values) as $i => $v)

      @php
        $x = round($i * ($barW + $gap), 1);
        $barH = $v !== null ? max(2, ($v / $max) * $chartH) : 0;
      @endphp
      @if($v !== null)
        <rect x="{{ $x }}" y="{{ round($chartH - $barH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($barH, 1) }}" rx="3" fill="var(--accent)" />
        <text x="{{ round($x + $barW / 2, 1) }}" y="{{ round($chartH - $barH - 8, 1) }}" text-anchor="middle" font-size="11" font-weight="600" fill="var(--ink)" font-variant-numeric="tabular-nums">{{ $v }}{{ $suffix }}</text>
      @endif
      <text x="{{ round($x + $barW / 2, 1) }}" y="{{ $chartH + 20 }}" text-anchor="middle" font-size="10" fill="var(--mut)">{{ $labels[$i] ?? '' }}</text>
    @endforeach
  </svg>
@else

  @php
    $min = $plotted->isNotEmpty() ? $plotted->min() : 0;
    $max = $plotted->isNotEmpty() ? $plotted->max() : 100;
    $spread = $max - $min;
    $pad = max(3, $spread * 0.35);
    $yLow = max(0, $min - $pad);
    $yHigh = min(100, $max + $pad);
    $yRange = max(0.01, $yHigh - $yLow);
    $stepX = $count > 1 ? $chartW / ($count - 1) : 0;
    $points = collect($values)->values()->map(function ($v, $i) use ($stepX, $count, $chartW, $chartH, $yLow, $yRange) {
      if ($v === null) {
        return null;
      }
      $x = $count > 1 ? $i * $stepX : $chartW / 2;
      $y = $chartH - (($v - $yLow) / $yRange * $chartH);

      return ['x' => round($x, 1), 'y' => round($y, 1)];
    });
    $polyline = $points->filter()->map(fn ($p) => "{$p['x']},{$p['y']}")->implode(' ');
  @endphp
  <svg viewBox="-8 -22 {{ $chartW + 16 }} {{ $chartH + 46 }}" style="width:100%;height:150px;overflow:visible" role="img" aria-label="{{ $ariaLabel ?? 'Trend chart' }}">
    <polyline points="{{ $polyline }}" fill="none" stroke="var(--accent)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
    @foreach($points as $i => $p)
      @if($p)
        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4" fill="var(--panel)" stroke="var(--accent)" stroke-width="2.5" />
        <text x="{{ $p['x'] }}" y="{{ $p['y'] - 12 }}" text-anchor="middle" font-size="11" font-weight="600" fill="var(--ink)" font-variant-numeric="tabular-nums">{{ $values[$i] }}{{ $suffix }}</text>
      @endif
      <text x="{{ $count > 1 ? $i * $stepX : $chartW / 2 }}" y="{{ $chartH + 20 }}" text-anchor="middle" font-size="10" fill="var(--mut)">{{ $labels[$i] ?? '' }}</text>
    @endforeach
  </svg>
@endif
