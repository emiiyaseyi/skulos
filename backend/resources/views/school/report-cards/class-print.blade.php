@extends('layouts.app', ['accent' => $accent])
@section('title', $class->name.' Report Cards')
@section('bare', true)

@push('head')
<style>
  body{background:#e9ebee}
  .rc-wrap{max-width:56rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  .rc-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
  .rc-sheet{position:relative;background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12);margin-bottom:1.5rem;font-size:.72rem;min-height:79.2rem}
  {{-- Section watermark, mirrors render.blade.php's own copy (2026-09-21,
       rebuilt 2026-09-23 to a single large centered image, see there). --}}
  .rc-watermark{position:absolute;inset:0;background-repeat:no-repeat;background-position:center 42%;background-size:62%;opacity:.22;z-index:-1;pointer-events:none}
  .rc-watermark-text{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;overflow:hidden;z-index:-1;pointer-events:none}
  .rc-watermark-text span{font-size:3.4rem;font-weight:800;color:rgba(0,0,0,.07);transform:rotate(-30deg);white-space:nowrap;text-transform:uppercase;letter-spacing:.06em}
  .rc-sheet p{margin:.2rem 0 0}
  .rc-head{display:flex;align-items:center;gap:.8rem;text-align:center;border-bottom:2px solid var(--accent);padding-bottom:.6rem;margin-bottom:.7rem}
  .rc-head-text{flex:1;min-width:0}
  .rc-head .rc-logo,.rc-head .rc-photo{flex:none;margin:0}
  .rc-head h1{font-size:2.1rem;font-weight:800}
  .rc-head p{font-size:.7rem;color:#555;margin:.15rem 0 0}
  .rc-contact-line{display:flex;flex-wrap:wrap;justify-content:center;gap:.15rem .8rem}
  .rc-contact-item{display:inline-flex;align-items:center;gap:.25rem}
  .rc-contact-item svg{width:.65rem;height:.65rem;flex-shrink:0}
  .rc-head .term{font-size:.78rem;font-weight:700;margin-top:.3rem;text-transform:uppercase;letter-spacing:.04em}
  .rc-id{display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;font-size:.7rem;margin-bottom:.8rem}
  .rc-id div b{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.04em;color:#777;font-weight:700}
  .rc-table{width:100%;border-collapse:collapse;font-size:.7rem;margin-bottom:.7rem}
  .rc-table th,.rc-table td{border:1px solid #d8d8d8;padding:.32rem .4rem;text-align:left}
  .rc-table th{background:#f3f4f6;font-size:.62rem;text-transform:uppercase;letter-spacing:.03em}
  .rc-table td.num,.rc-table th.num{text-align:center;font-variant-numeric:tabular-nums}
  .rc-table-hint{display:none;font-size:.66rem;color:#888;margin:-.5rem 0 .7rem;text-align:right}
  @media(max-width:640px){.rc-table-hint{display:block}}
  .rc-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(5.5rem,1fr));gap:.5rem;margin:.7rem 0}
  .rc-summary .box{background:#f7f7f8;border-radius:8px;padding:.5rem;text-align:center}
  .rc-summary .box b{font-size:1rem;display:block}
  .rc-summary .box span{font-size:.6rem;text-transform:uppercase;color:#777;font-weight:700}
  .rc-remarks div{margin-bottom:.5rem;font-size:.72rem;text-align:center}
  .rc-remarks b{display:block;font-size:.64rem;text-transform:uppercase;letter-spacing:.03em;color:#777;margin-bottom:.1rem}
  .rc-tally{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.7rem}
  .rc-tally span{background:#f3f4f6;border-radius:6px;padding:.2rem .5rem;font-size:.66rem;font-weight:700}
  .rc-modern .rc-head{background:linear-gradient(135deg,var(--accent),#2a2a2a);color:#fff;border-radius:10px 10px 0 0;border-bottom:none;padding:1rem}
  .rc-modern .rc-head p{color:rgba(255,255,255,.85)}
  .rc-compact .rc-table{font-size:.64rem}
  {{-- bordered / vibrant (Templates 7 and 8, 2026-07-28), same as render.blade.php's own copy. --}}
  .rc-bordered{border:6px solid var(--accent);padding:1.6rem}
  .rc-bordered .rc-head{border-bottom:3px solid var(--accent);padding-bottom:.8rem}
  .rc-bordered .rc-table th{background:var(--accent);color:var(--accent-ink)}
  .rc-bordered .rc-table td,.rc-bordered .rc-table th{border:1.5px solid var(--accent)}
  .rc-bordered .rc-summary .box{border:1.5px solid var(--accent)}
  .rc-vibrant .rc-head{background:color-mix(in srgb, var(--accent) 12%, #fff);border-radius:14px 14px 0 0;border-bottom:none;padding:1rem}
  .rc-vibrant .rc-table{border-radius:8px;overflow:hidden}
  .rc-vibrant .rc-table th{background:var(--accent);color:var(--accent-ink)}
  .rc-vibrant .rc-table tbody tr:nth-child(even){background:color-mix(in srgb, var(--accent) 6%, #fff)}
  .rc-vibrant .rc-summary .box{background:color-mix(in srgb, var(--accent) 10%, #fff);border-radius:14px}
  .rc-logo{width:104px;height:104px;object-fit:contain;display:block;margin:0 auto .4rem}
  {{-- Student Photo layout designer block, mirrors render.blade.php (2026-07-24, was missing here so bulk class printing dropped the photo silently). --}}
  .rc-photo{width:76px;height:76px;object-fit:cover;border-radius:10px;display:block;margin:0 auto .4rem;border:1px solid #d8d8d8;overflow:hidden}
  .rc-photo svg{width:100%;height:100%;display:block}
  .rc-photo-placeholder{display:grid;place-items:center;background:#f3f4f6;color:#888;font-weight:800;font-size:1.15rem}
  .rc-head-custom{display:flex;flex-direction:column;align-items:center;gap:.25rem}
  .rc-head-custom .rc-logo{margin-bottom:0}
  /* Kept in sync with render.blade.php's own copy of this rule -- see
     its comment there for why this is centered (2026-08-15). */
  .rc-eval .rc-head{display:flex;align-items:center;gap:.8rem;text-align:center;border-bottom:2px solid var(--accent);padding-bottom:.6rem}
  .rc-eval .rc-head .rc-logo{margin:0;flex:none}
  .rc-eval .rc-head-text{flex:1}
  .rc-eval .rc-head h1{font-size:1.9rem}
  .rc-block{margin-bottom:.7rem}
  .rc-block h3{font-size:.66rem;text-align:center;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:1px solid #e2e2e2;padding-bottom:.25rem;margin-bottom:.4rem}
  .rc-twocol{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
  .rc-cols{display:grid;grid-template-columns:repeat(var(--rc-cols,2),1fr);gap:1rem}
  .rc-traits{width:100%;border-collapse:collapse;font-size:.68rem}
  .rc-traits td{padding:.22rem 0;border-bottom:1px dashed #e2e2e2}
  .rc-traits td.val{text-align:right;font-weight:700}
  {{-- Real bordered tables (2026-09-22, reference report card): the
       Attendance/Physical block and the closing Remarks block, every
       template. --}}
  .rc-bordered-table{width:100%;border-collapse:collapse;font-size:.68rem;margin-bottom:.4rem}
  .rc-bordered-table th,.rc-bordered-table td{border:1px solid #444;padding:.3rem .45rem;text-align:left;vertical-align:top}
  .rc-bordered-table th{background:#f3f4f6;font-size:.6rem;text-transform:uppercase;letter-spacing:.03em;text-align:center}
  .rc-bordered-table b{text-transform:uppercase;letter-spacing:.02em;font-size:.62rem}
  .rc-identity-table{margin-bottom:.8rem}
  .rc-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:.4rem;font-size:.62rem}
  .rc-legend span{background:#f3f4f6;border-radius:6px;padding:.2rem .45rem}
  .rc-signoff{display:flex;justify-content:space-around;gap:1.2rem;text-align:center;margin-top:1rem;padding-top:.7rem;border-top:1px solid #e2e2e2}
  .rc-signoff img{max-height:44px;max-width:120px;object-fit:contain;display:block;margin:0 auto .25rem}
  .rc-signoff .line{border-top:1px solid #999;margin-top:.3rem;padding-top:.25rem;font-size:.58rem;text-transform:uppercase;letter-spacing:.03em;color:#777}
  .rc-footer{margin-top:.8rem;padding-top:.6rem;border-top:1px solid #e2e2e2;font-size:.66rem;color:#777}
  @media (max-width:640px){ .rc-twocol{grid-template-columns:1fr} .rc-cols{grid-template-columns:1fr} }
  {{-- One student per printed page: a page-break BEFORE every sheet
       except the first, so "Print" here produces exactly one report
       card per page, in one PDF, matching what saving each individually
       would have produced, per the 2026-07-21 A4 fit fix above. --}}
  @page{ size:A4; margin:10mm 12mm; }
  .rc-print-page{ break-after:page }
  .rc-print-page:last-child{ break-after:auto }
  @media print{
    /* Root font-size scale, print only, mirrors render.blade.php's
       identical fix (2026-07-24): every spacing/font value here is in
       rem (relative to the root element, not .rc-sheet's own), so this
       is the one place that actually shrinks all of them together in
       proportion, rather than a dozen individual paddings needing
       separate re-tuning. */
    html{font-size:13.5px}
    body{background:#fff}
    .rc-bar,.appbar,.side,.impersonate-bar,.rc-table-hint{display:none !important}
    .content{padding:0 !important}
    .rc-wrap{max-width:none;margin:0;padding:0}
    .rc-sheet{box-shadow:none;border-radius:0;padding:0;margin-bottom:0;min-height:auto}
    .rc-sheet p{margin:.15rem 0 0}
    .rc-head{padding-bottom:.4rem;margin-bottom:.5rem}
    .rc-modern .rc-head{padding:.6rem}
    .rc-id{margin-bottom:.5rem}
    .rc-table{margin-bottom:.5rem}
    .rc-table th,.rc-table td{padding:.18rem .3rem}
    .rc-summary{margin:.5rem 0}
    .rc-summary .box{padding:.35rem}
    .rc-block{margin-bottom:.35rem}
    .rc-traits td{padding:.14rem 0}
    .rc-bordered-table th,.rc-bordered-table td{padding:.2rem .3rem}
    .rc-remarks div{margin-bottom:.35rem}
    .rc-signoff{margin-top:.5rem;padding-top:.4rem}
    .rc-footer{margin-top:.5rem;padding-top:.4rem}
    .rc-signoff img{max-height:36px}
    .rc-logo,.rc-photo{width:44px;height:44px}
    /* Score Trend/Subject Breakdown print sizing, mirrors render.blade.php (2026-07-24): both opt-in and both heavy, shrunk rather than chased to zero overflow. */
    .rc-block svg{max-width:19rem !important}
    /* .rc-table deliberately excluded, see render.blade.php's identical
       fix (2026-07-23): break-inside:avoid on the whole table pushes it
       entirely onto the next page rather than starting right after the
       header, leaving a long blank page 1 for any class with many
       subjects. Only rows need to stay intact. */
    .rc-summary,.rc-block,.rc-signoff,.rc-tally,.rc-strip,.rc-footer{break-inside:avoid}
    .rc-table tr{break-inside:avoid}
    .rc-remarks div{break-inside:avoid}
    .rc-bordered-table tr{break-inside:avoid}
  }
</style>
@endpush

@section('content')
<div class="rc-wrap">
  <div class="rc-bar">
    <div>
      <strong>{{ $class->name }}</strong>
      <span class="muted" style="font-size:.85rem;display:block">{{ $term->name }} &middot; {{ \App\Models\Tenant\ReportCardReview::reportTypeLabel($report_type ?? 'full_term') }} &middot; {{ $reportData->count() }} student(s)</span>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="window.print()" type="button">Print all</button>
  </div>

  @forelse($reportData as $data)
    <div class="rc-print-page">
      @include('school.report-cards._sheet')
    </div>
  @empty
    <div class="rc-sheet"><p class="muted">No active students in this class yet.</p></div>
  @endforelse
</div>
@endsection
