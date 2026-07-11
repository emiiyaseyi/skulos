@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Dashboard look'])
@section('title', 'Dashboard Look')

@push('head')
<style>
.layout-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem}
.layout-opt{cursor:pointer;display:block}
.layout-opt input{position:absolute;opacity:0}
.layout-card{border:2px solid var(--line);border-radius:14px;padding:1rem;background:var(--panel);transition:border-color .15s ease,box-shadow .15s ease}
.layout-opt input:checked + .layout-card{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}
.layout-opt input:focus-visible + .layout-card{outline:2px solid var(--accent);outline-offset:2px}
.layout-name{font-weight:800;font-size:.95rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:.2rem}
.layout-desc{font-size:.8rem;color:var(--mut);margin-bottom:.7rem;min-height:2.4em}
.mini{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:.5rem;display:grid;gap:.35rem;height:104px}
.mini .bar{background:color-mix(in srgb,var(--accent) 30%,var(--soft));border-radius:4px}
.mini .box{background:var(--soft);border:1px solid var(--line);border-radius:4px}
.mini-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.35rem;height:22px}
.mini-two{display:grid;grid-template-columns:1fr 1fr;gap:.35rem;flex:1}
.tick{width:18px;height:18px;border-radius:50%;border:2px solid var(--line);display:inline-grid;place-items:center;font-size:11px}
.layout-opt input:checked + .layout-card .tick{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.wtoggle{display:flex;align-items:center;gap:.6rem;padding:.7rem 0;border-bottom:1px solid var(--line)}
.wtoggle:last-child{border-bottom:none}
.wtoggle input{width:auto}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Dashboard look</h1>
    <p class="subtitle">Choose how your school dashboard is arranged, and pick which panels appear.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">Back to dashboard</a>
</div>

<form method="POST" action="{{ route('school.dashboard.update', ['school' => $school->slug]) }}">
  @csrf

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:1rem">Layout</h3>
    <div class="layout-grid">
      @foreach(config('skulos.dashboard_layouts') as $key => $meta)
        <label class="layout-opt">
          <input type="radio" name="layout" value="{{ $key }}" @checked($currentLayout === $key) />
          <div class="layout-card">
            <div class="layout-name">{{ $meta['label'] }} <span class="tick">✓</span></div>
            <div class="layout-desc">{{ $meta['description'] }}</div>
            <div class="mini">
              @switch($key)
                @case('vibrant')
                  <div class="bar" style="height:20px"></div>
                  <div class="mini-row" style="height:26px"><div class="bar"></div><div class="bar"></div><div class="bar"></div></div>
                  @break
                @case('erp')
                  <div class="mini-row" style="height:30px"><div class="bar"></div><div class="bar"></div><div class="bar"></div></div>
                  <div class="box" style="flex:1"></div>
                  @break
                @case('modern')
                  <div class="bar" style="height:18px"></div>
                  <div class="mini-row" style="height:20px;border-radius:999px"><div class="box" style="border-radius:999px"></div><div class="box" style="border-radius:999px"></div><div class="box" style="border-radius:999px"></div></div>
                  <div class="mini-two"><div class="box"></div><div class="box"></div></div>
                  @break
                @default {{-- classic --}}
                  <div class="mini-row"><div class="box"></div><div class="box"></div><div class="box"></div></div>
                  <div class="mini-two"><div class="box"></div><div class="box"></div></div>
              @endswitch
            </div>
          </div>
        </label>
      @endforeach
    </div>
  </div>

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.4rem">Panels</h3>
    <p class="muted" style="margin-bottom:.6rem">Show or hide what appears on the dashboard.</p>
    @foreach(config('skulos.dashboard_widgets') as $code => $meta)
      <label class="wtoggle">
        <input type="checkbox" name="widgets[{{ $code }}]" value="1" @checked($widgets[$code] ?? false) />
        <span><b>{{ $meta['label'] }}</b></span>
      </label>
    @endforeach
  </div>

  <button class="btn btn-primary" type="submit">Save dashboard look</button>
</form>
@endsection
