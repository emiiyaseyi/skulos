@extends('layouts.app', ['accent' => $accent])
@section('title', $class->name.' Report Cards')
@section('bare', true)

@push('head')
<style>
  body{background:#e9ebee}
  .rc-wrap{max-width:56rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  .rc-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
  .rc-sheet{background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12);margin-bottom:1.5rem}
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
  .rc-modern .rc-head{background:linear-gradient(135deg,var(--accent),#2a2a2a);color:#fff;border-radius:10px 10px 0 0;border-bottom:none;padding:1.4rem}
  .rc-modern .rc-head p{color:rgba(255,255,255,.85)}
  .rc-modern .photo{width:64px;height:64px;border-radius:12px;background:#eee;margin:0 auto .6rem;display:grid;place-items:center;font-weight:800;color:#888}
  .rc-compact .rc-table{font-size:.78rem}
  .rc-compact .rc-summary{grid-template-columns:repeat(3,1fr)}
  .rc-logo{width:60px;height:60px;object-fit:contain;display:block;margin:0 auto .5rem}
  .rc-head-custom{display:flex;flex-direction:column;align-items:center;gap:.3rem}
  .rc-head-custom .rc-logo{margin-bottom:0}
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
  {{-- One student per printed page: a page-break BEFORE every sheet
       except the first, so "Print" here produces exactly one report
       card per page, in one PDF, matching what saving each individually
       would have produced, per the 2026-07-21 A4 fit fix above. --}}
  @page{ size:A4; margin:10mm 12mm; }
  .rc-print-page{ break-after:page }
  .rc-print-page:last-child{ break-after:auto }
  @media print{
    body{background:#fff}
    .rc-bar,.appbar,.side,.impersonate-bar{display:none !important}
    .content{padding:0 !important}
    .rc-wrap{max-width:none;margin:0;padding:0}
    .rc-sheet{box-shadow:none;border-radius:0;padding:0;font-size:.92em;margin-bottom:0}
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
<div class="rc-wrap">
  <div class="rc-bar">
    <div>
      <strong>{{ $class->name }}</strong>
      <span class="muted" style="font-size:.85rem;display:block">{{ $term->name }} &middot; {{ $reportData->count() }} student(s)</span>
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
