@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Report card look'])
@section('title', 'Report Card Look')

@push('head')
<style>
.rc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem}
.rc-opt{cursor:pointer;display:block}
.rc-opt input{position:absolute;opacity:0}
.rc-card{border:2px solid var(--line);border-radius:14px;padding:1rem;background:var(--panel)}
.rc-opt input:checked + .rc-card{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}
.rc-name{font-weight:800;font-size:.95rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:.2rem}
.rc-desc{font-size:.8rem;color:var(--mut);margin-bottom:.7rem;min-height:2.6em}
.rc-mini{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:.5rem;display:grid;gap:4px;height:90px}
.rc-mini .row{height:8px;border-radius:2px;background:var(--soft);border:1px solid var(--line)}
.rc-mini .row.hi{background:color-mix(in srgb,var(--accent) 35%,var(--soft))}
.tick{width:18px;height:18px;border-radius:50%;border:2px solid var(--line);display:inline-grid;place-items:center;font-size:11px}
.rc-opt input:checked + .rc-card .tick{background:var(--accent);border-color:var(--accent);color:#241503}
.ftoggle{display:flex;align-items:center;gap:.6rem;padding:.6rem 0;border-bottom:1px solid var(--line)}
.ftoggle:last-child{border-bottom:none}
.ftoggle input{width:auto}
.fgroup{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);margin:1rem 0 .3rem}
.fgroup:first-child{margin-top:0}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Report card look</h1>
    <p class="subtitle">Choose a template and which fields appear on your school's report cards.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.preview', ['school' => $school->slug]) }}" target="_blank" rel="noopener">Preview current look</a>
</div>

<form method="POST" action="{{ route('school.report-cards.update', ['school' => $school->slug]) }}">
  @csrf

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:1rem">Template</h3>
    <div class="rc-grid">
      @foreach(config('skulos.report_card_templates') as $key => $meta)
        <label class="rc-opt">
          <input type="radio" name="template" value="{{ $key }}" @checked($currentTemplate === $key) />
          <div class="rc-card">
            <div class="rc-name">{{ $meta['label'] }} <span class="tick">&#10003;</span></div>
            <div class="rc-desc">{{ $meta['description'] }}</div>
            <div class="rc-mini">
              <div class="row hi" style="width:60%"></div>
              <div class="row" style="width:90%"></div>
              <div class="row" style="width:90%"></div>
              <div class="row" style="width:90%"></div>
              <div class="row" style="width:40%"></div>
            </div>
          </div>
        </label>
      @endforeach
    </div>
  </div>

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.4rem">Fields</h3>
    <p class="muted" style="margin-bottom:.4rem">Turn any field on or off. Custom additions can be requested from support.</p>
    @php($groups = ['identity' => 'Student details', 'academic' => 'Academic', 'remarks' => 'Remarks'])
    @foreach($groups as $groupKey => $groupLabel)
      <div class="fgroup">{{ $groupLabel }}</div>
      @foreach(config('skulos.report_card_fields') as $code => $meta)
        @continue($meta['group'] !== $groupKey)
        <label class="ftoggle">
          <input type="checkbox" name="fields[{{ $code }}]" value="1" @checked($fields[$code] ?? false) />
          <span>{{ $meta['label'] }}</span>
        </label>
      @endforeach
    @endforeach
  </div>

  <button class="btn btn-primary" type="submit">Save report card look</button>
</form>
@endsection
