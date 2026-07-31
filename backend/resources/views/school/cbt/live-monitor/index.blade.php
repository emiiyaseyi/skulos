@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Live Monitor'])
@section('title', 'Live Monitor')

@section('content')
<meta http-equiv="refresh" content="8">
<div class="page-head">
  <div>
    <h1 class="title">Live Monitor</h1>
    <p class="subtitle">Every assessment attempt in progress right now. Refreshes automatically every 8 seconds.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

@if($prechecks->isNotEmpty())
  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.6rem">Still in pre-check ({{ $prechecks->count() }})</h3>
    <div style="display:flex;flex-wrap:wrap;gap:.6rem">
      @foreach($prechecks as $precheck)
        <span class="badge badge-warn">{{ $precheck->takeable?->name ?? 'Unknown' }} &middot; {{ $precheck->paper?->title }} &middot; {{ $precheck->current_step }}</span>
      @endforeach
    </div>
  </div>
@endif

@if($sessions->isEmpty())
  <div class="card"><p class="muted">No assessments in progress right now.</p></div>
@else
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(18rem,1fr));gap:1rem">
    @foreach($sessions as $session)
      @php
        $riskColor = match($session->risk_level) { 'high' => 'var(--bad,#dc3545)', 'medium' => 'var(--warn,#e0a800)', default => 'var(--ok,#28a745)' };
        $events = $session->securityEvents->sortBy('occurred_at')->values();
        $cumulative = 0;
        $points = [[0, 0]];
        foreach ($events as $i => $ev) {
          $cumulative += $ev->score_impact;
          $points[] = [$i + 1, $cumulative];
        }
        $maxY = max(1, $cumulative);
        $maxX = max(1, count($points) - 1);
        $svgPoints = collect($points)->map(function ($p) use ($maxX, $maxY) {
          $x = round(($p[0] / $maxX) * 100, 1);
          $y = round(30 - (($p[1] / $maxY) * 28), 1);
          return "{$x},{$y}";
        })->implode(' ');
      @endphp
      <div class="card" style="border-top:3px solid {{ $riskColor }}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.4rem">
          <div>
            <strong>{{ $session->takeable?->name ?? 'Unknown' }}</strong>
            <div class="muted" style="font-size:.85rem">{{ $session->paper?->title }}</div>
          </div>
          <span class="badge" style="background:{{ $riskColor }};color:#fff">{{ ucfirst($session->risk_level) }} &middot; {{ $session->risk_score }}</span>
        </div>
        <svg viewBox="0 0 100 30" style="width:100%;height:2rem;margin-bottom:.4rem" preserveAspectRatio="none">
          <polyline points="{{ $svgPoints }}" fill="none" stroke="{{ $riskColor }}" stroke-width="2" />
        </svg>
        <div class="muted" style="font-size:.8rem;margin-bottom:.5rem">
          Tab switches: {{ $session->tab_switches }} &middot; Fullscreen exits: {{ $session->fullscreen_exits }} &middot; Face flags: {{ $session->face_missing_count }}
        </div>
        @if($session->break_requested)
          <form method="POST" action="{{ route('school.cbt.live-monitor.approve-break', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:flex;gap:.4rem;align-items:center;margin-bottom:.4rem">
            @csrf
            <span class="badge badge-warn">Break requested{{ $session->break_reason ? ': '.$session->break_reason : '' }}</span>
            <input type="number" name="minutes" value="5" min="1" max="60" style="width:4rem" />
            <button class="btn btn-primary btn-sm" type="submit">Approve</button>
          </form>
          <form method="POST" action="{{ route('school.cbt.live-monitor.deny-break', ['school' => $school->slug, 'sessionId' => $session->id]) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit">Deny break</button>
          </form>
        @elseif($session->isOnBreak())
          <span class="badge badge-warn">On break until {{ $session->break_ends_at->format('H:i') }}</span>
        @else
          <form method="POST" action="{{ route('school.cbt.incidents.raise', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:flex;gap:.4rem">
            @csrf
            <input type="text" name="description" placeholder="Raise an incident..." style="flex:1" required />
            <button class="btn btn-ghost btn-sm" type="submit">Raise</button>
          </form>
        @endif
      </div>
    @endforeach
  </div>
@endif
@endsection
