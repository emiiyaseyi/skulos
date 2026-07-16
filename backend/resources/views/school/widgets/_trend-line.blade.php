{{--
  Shared SVG trend line (same zoomed-axis approach already used for the
  dashboard's "Result performance" widget, see role-panel.blade.php's own
  copy of this math): a trend LINE's vertical position is relative, not a
  magnitude encoding the way a bar's length is, so zooming the y-axis to
  the data's own range is legitimate here (never for a bar chart, whose
  baseline must stay at 0). Expects $values (list<float>, null entries
  become gaps) and $labels (same length, x-axis captions); $suffix
  (default '%') is appended to each point's number label.
--}}
@php
  $suffix = $suffix ?? '%';
  $plotted = collect($values)->filter(fn ($v) => $v !== null);
  $min = $plotted->isNotEmpty() ? $plotted->min() : 0;
  $max = $plotted->isNotEmpty() ? $plotted->max() : 100;
  $spread = $max - $min;
  $pad = max(3, $spread * 0.35);
  $yLow = max(0, $min - $pad);
  $yHigh = min(100, $max + $pad);
  $yRange = max(0.01, $yHigh - $yLow);
  $count = count($values);
  $chartW = 600;
  $chartH = 100;
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
