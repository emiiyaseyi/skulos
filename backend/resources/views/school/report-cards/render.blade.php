@extends('layouts.app', ['accent' => $accent])
@section('title', 'Report Card')
@section('bare', true)

@push('head')
<style>
  body{background:#e9ebee}
  .rc-wrap{max-width:56rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  .rc-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
  .rc-bar .tag{font-size:.75rem;font-weight:700;padding:.3rem .7rem;border-radius:999px;background:var(--accent);color:var(--accent-ink)}
  .rc-sheet{background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12)}
  .rc-head{text-align:center;border-bottom:3px solid var(--accent);padding-bottom:1rem;margin-bottom:1rem}
  .rc-head h1{font-size:1.4rem;font-weight:800}
  .rc-head p{font-size:.85rem;color:#555}
  .rc-head .term{font-size:.95rem;font-weight:700;margin-top:.4rem;text-transform:uppercase;letter-spacing:.04em}
  .rc-id{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;font-size:.82rem;margin-bottom:1.1rem}
  .rc-id div b{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:#777;font-weight:700}
  .rc-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:1rem}
  .rc-table th,.rc-table td{border:1px solid #d8d8d8;padding:.45rem .5rem;text-align:left}
  .rc-table th{background:#f3f4f6;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em}
  .rc-table td.num,.rc-table th.num{text-align:center;font-variant-numeric:tabular-nums}
  .rc-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:.7rem;margin:1rem 0}
  .rc-summary .box{background:#f7f7f8;border-radius:8px;padding:.7rem;text-align:center}
  .rc-summary .box b{font-size:1.15rem;display:block}
  .rc-summary .box span{font-size:.68rem;text-transform:uppercase;color:#777;font-weight:700}
  .rc-remarks div{margin-bottom:.7rem;font-size:.85rem}
  .rc-remarks b{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#777;margin-bottom:.15rem}
  .rc-tally{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
  .rc-tally span{background:#f3f4f6;border-radius:6px;padding:.25rem .55rem;font-size:.75rem;font-weight:700}
  /* modern */
  .rc-modern .rc-head{background:linear-gradient(135deg,var(--accent),#2a2a2a);color:#fff;border-radius:10px 10px 0 0;border-bottom:none;padding:1.4rem}
  .rc-modern .rc-head p{color:rgba(255,255,255,.85)}
  .rc-modern .photo{width:64px;height:64px;border-radius:12px;background:#eee;margin:0 auto .6rem;display:grid;place-items:center;font-weight:800;color:#888}
  /* compact */
  .rc-compact .rc-table{font-size:.78rem}
  .rc-compact .rc-summary{grid-template-columns:repeat(3,1fr)}
  .rc-logo{width:60px;height:60px;object-fit:contain;display:block;margin:0 auto .5rem}
  /* Layout designer custom header/id blocks (2026-07-18) */
  .rc-head-custom{display:flex;flex-direction:column;align-items:center;gap:.3rem}
  .rc-head-custom .rc-logo{margin-bottom:0}
  /* evaluation / narrative (Templates 5 and 6) */
  .rc-eval .rc-head{display:flex;align-items:center;gap:1rem;text-align:left;border-bottom:3px solid var(--accent);padding-bottom:1rem}
  .rc-eval .rc-head .rc-logo{margin:0;flex:none}
  .rc-eval .rc-head-text{flex:1}
  .rc-eval .rc-head h1{font-size:1.25rem}
  .rc-eval .rc-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;background:#f3f4f6;border-radius:8px;padding:.6rem .8rem;font-size:.82rem;margin-bottom:1.1rem}
  .rc-eval .rc-strip div b{display:block;font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#777;font-weight:700}
  .rc-block{margin-bottom:1.1rem}
  .rc-block > h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:1px solid #e2e2e2;padding-bottom:.3rem;margin-bottom:.5rem}
  .rc-twocol{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem}
  .rc-traits{width:100%;border-collapse:collapse;font-size:.8rem}
  .rc-traits td{padding:.3rem 0;border-bottom:1px dashed #e2e2e2}
  .rc-traits td.val{text-align:right;font-weight:700}
  .rc-legend{display:flex;flex-wrap:wrap;gap:.5rem;font-size:.72rem}
  .rc-legend span{background:#f3f4f6;border-radius:6px;padding:.25rem .55rem}
  .rc-signoff{display:flex;justify-content:space-around;gap:1.5rem;text-align:center;margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e2e2e2}
  .rc-signoff img{max-height:52px;max-width:140px;object-fit:contain;display:block;margin:0 auto .3rem}
  .rc-signoff .line{border-top:1px solid #999;margin-top:.4rem;padding-top:.3rem;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;color:#777}
  @media (max-width:640px){ .rc-twocol{grid-template-columns:1fr} .rc-eval .rc-strip{grid-template-columns:1fr} }
  {{--
    Fit on one A4 page (user report 2026-07-21: "Report cards need to be
    fitted into one A4 page without it spilling into other pages and
    lines"). Two separate problems: no @page rule at all meant every
    browser fell back to its own default print margins (often 12-19mm a
    side, compounding with .rc-wrap/.rc-sheet's own screen padding meant
    for a card floating on a grey background, not a printed sheet), and
    nothing stopped a table row, block, or the sign-off strip from
    splitting mid-element across a page boundary once content did run
    long. Explicit @page sizing/margins plus tighter print-only spacing
    covers realistic single-term data on one page; break-inside:avoid on
    every row/section is the fallback for whatever still doesn't, so an
    unusually long class (many subjects, long comments) breaks cleanly
    between sections instead of mid-row.
  --}}
  @page{ size:A4; margin:10mm 12mm; }
  @media print{
    body{background:#fff}
    .rc-bar,.appbar,.side,.impersonate-bar{display:none !important}
    .content{padding:0 !important}
    .rc-wrap{max-width:none;margin:0;padding:0}
    .rc-sheet{box-shadow:none;border-radius:0;padding:0;font-size:.92em}
    .rc-head{padding-bottom:.5rem;margin-bottom:.6rem}
    .rc-id{margin-bottom:.6rem}
    .rc-table{margin-bottom:.6rem}
    .rc-table th,.rc-table td{padding:.3rem .4rem}
    .rc-summary{margin:.6rem 0}
    .rc-block{margin-bottom:.6rem}
    .rc-signoff{margin-top:.8rem;padding-top:.6rem}
    .rc-table,.rc-summary,.rc-block,.rc-signoff,.rc-tally,.rc-strip{break-inside:avoid}
    .rc-table tr{break-inside:avoid}
    .rc-remarks div{break-inside:avoid}
  }
</style>
@endpush

@section('content')
@php($show = fn($f) => $fields[$f] ?? false)
@php($isEval = in_array($template, ['evaluation', 'narrative'], true))
@php($logoUrl = $school->logo_path ? \Illuminate\Support\Facades\Storage::url($school->logo_path) : null)
<div class="rc-wrap">
  <div class="rc-bar">
    @if($isPreview)<span class="tag">Preview, sample data</span>@else<span></span>@endif
    <button class="btn btn-ghost btn-sm" onclick="window.print()" type="button">Print</button>
  </div>

  <div class="rc-sheet {{ $template === 'modern' ? 'rc-modern' : ($template === 'compact' ? 'rc-compact' : ($isEval ? 'rc-eval' : '')) }}">
    @if(($layoutEnabled ?? false) && ! empty($layoutZones))
      <div class="rc-head rc-head-custom">
        @foreach(($layoutZones['header'] ?? []) as $block)
          <div style="align-self:{{ ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][($headerAlign ?? [])[$block] ?? 'center'] }}">
            @include('school.report-cards._layout-block', ['block' => $block])
          </div>
        @endforeach
      </div>
      @if(! empty($layoutZones['info']))
        <div class="rc-id">
          @foreach($layoutZones['info'] as $block)
            @include('school.report-cards._layout-block', ['block' => $block])
          @endforeach
        </div>
      @endif
    @else
    <div class="rc-head">
      @if($isEval)
        @if($logoUrl)<img class="rc-logo" src="{{ $logoUrl }}" alt="" />@endif
        <div class="rc-head-text">
          <h1>{{ $school->name }}</h1>
          <p>{{ $school->address ?: $school->motto }}</p>
          <div class="term">{{ $data['term']['name'] }} Report &middot; {{ $data['term']['session'] }}</div>
        </div>
      @else
        @if($template === 'modern')
          @if($logoUrl)<img class="rc-logo" src="{{ $logoUrl }}" alt="" />@else<div class="photo">{{ strtoupper(substr($data['identity']['name'],0,1)) }}</div>@endif
        @endif
        <h1>{{ $school->name }}</h1>
        <p>{{ $school->address ?: $school->motto }}</p>
        <div class="term">{{ $data['term']['name'] }} Report &middot; {{ $data['term']['session'] }}</div>
      @endif
    </div>

    @if($isEval)
      <div class="rc-strip">
        <div><b>Name</b>{{ $data['identity']['name'] }}</div>
        @if($show('class'))<div><b>Class</b>{{ $data['identity']['class'] }}</div>@endif
        @if($show('next_term_begins'))<div><b>Next Term Begins</b>{{ $data['remarks']['next_term_begins_on'] ?: 'To be announced.' }}</div>@endif
      </div>
    @endif

    <div class="rc-id">
      @unless($isEval)<div><b>Name</b>{{ $data['identity']['name'] }}</div>@endunless
      @if($show('admission_number'))<div><b>Admission No.</b>{{ $data['identity']['admission_number'] }}</div>@endif
      @if($show('class') && ! $isEval)<div><b>Class</b>{{ $data['identity']['class'] }}</div>@endif
      @if($show('gender'))<div><b>Gender</b>{{ $data['identity']['gender'] }}</div>@endif
      @if($show('date_of_birth'))<div><b>Date of Birth</b>{{ $data['identity']['date_of_birth'] }}</div>@endif
      @if($show('age'))<div><b>Age</b>{{ $data['identity']['age'] }}</div>@endif
      @if($show('house'))<div><b>House</b>{{ $data['identity']['house'] }}</div>@endif
      @if($show('class_teacher'))<div><b>Class Teacher</b>{{ $data['identity']['class_teacher'] }}</div>@endif
      @if($show('attendance'))<div><b>Attendance</b>{{ $data['attendance']['present'] }} of {{ $data['attendance']['total'] }}</div>@endif
    </div>
    @endif

    @foreach($bodySections as $sectionKey)
      @if((($bodyAlign ?? [])[$sectionKey] ?? 'stretch') === 'stretch')
        @if(str_starts_with($sectionKey, 'custom_'))
          @include('school.report-cards._body-custom', ['customBlock' => ($customBlocks ?? [])[$sectionKey] ?? null])
        @else
          @includeIf('school.report-cards._body-'.$sectionKey)
        @endif
      @else
        <div style="max-width:75%;{{ ['left' => 'margin-right:auto', 'right' => 'margin-left:auto', 'center' => 'margin-left:auto;margin-right:auto'][$bodyAlign[$sectionKey]] }}">
          @if(str_starts_with($sectionKey, 'custom_'))
            @include('school.report-cards._body-custom', ['customBlock' => ($customBlocks ?? [])[$sectionKey] ?? null])
          @else
            @includeIf('school.report-cards._body-'.$sectionKey)
          @endif
        </div>
      @endif
    @endforeach
  </div>
</div>
@endsection
