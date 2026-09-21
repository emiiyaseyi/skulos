@extends('layouts.app', ['accent' => $accent])
@section('title', 'Report Card')
@section('bare', true)

@push('head')
<style>
  body{background:#e9ebee}
  .rc-wrap{max-width:56rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  .rc-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
  .rc-bar .tag{font-size:.75rem;font-weight:700;padding:.3rem .7rem;border-radius:999px;background:var(--accent);color:var(--accent-ink)}
  .rc-sheet{position:relative;background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12);font-size:.72rem}
  {{-- Section watermark (2026-09-21): tiled behind everything via a
       negative z-index inside .rc-sheet's own stacking context
       (position:relative above), so no other rule in this file needs
       to know it exists. --}}
  .rc-watermark{position:absolute;inset:0;background-repeat:repeat;background-position:center;background-size:130px;opacity:.07;z-index:-1;pointer-events:none}
  {{-- Every <p> in a report card sheet used the browser's own default
       paragraph margin (about 1em top and bottom) with nothing to zero it
       out, real font/spacing culprit behind "so many spaces...too big"
       (user report 2026-07-24) alongside every size below being brought
       down toward 11-12px from their old ~13-14px. --}}
  .rc-sheet p{margin:.2rem 0 0}
  {{-- Logo left, student photo right, school name/address/term centered
       between them (user request 2026-07-24: "the school logo will be
       on the left side of the header, and the child image will be on
       the right side of the header... this section of the report card
       should be center aligned as default"). Nothing renders on either
       side when there is no logo/photo, no placeholder box holding the
       space (user: "if there's no image uploaded for the
       learner/student, nothing appears there"); the center text block's
       own flex:1 absorbs whichever side is empty, keeping it centered
       against the header as a whole rather than the remaining space. --}}
  .rc-head{display:flex;align-items:center;gap:.8rem;text-align:center;border-bottom:2px solid var(--accent);padding-bottom:.6rem;margin-bottom:.7rem}
  .rc-head-text{flex:1;min-width:0}
  .rc-head .rc-logo,.rc-head .rc-photo{flex:none;margin:0}
  {{-- Doubled (user request 2026-07-24: "let's double the size of the school name... it should be much bigger that the current one"). --}}
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
  {{-- auto-fit, not a fixed 4 column count: each of the 5 boxes here is
       now independently toggleable (2026-07-24), so the real count on
       any given report card varies from 1 to 5, this keeps every count
       resolving to one tidy row instead of a fixed grid leaving a gap
       or an orphaned box. --}}
  .rc-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(5.5rem,1fr));gap:.5rem;margin:.7rem 0}
  .rc-summary .box{background:#f7f7f8;border-radius:8px;padding:.5rem;text-align:center}
  .rc-summary .box b{font-size:1rem;display:block}
  .rc-summary .box span{font-size:.6rem;text-transform:uppercase;color:#777;font-weight:700}
  .rc-remarks div{margin-bottom:.5rem;font-size:.72rem;text-align:center}
  .rc-remarks b{display:block;font-size:.64rem;text-transform:uppercase;letter-spacing:.03em;color:#777;margin-bottom:.1rem}
  .rc-tally{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.7rem}
  .rc-tally span{background:#f3f4f6;border-radius:6px;padding:.2rem .5rem;font-size:.66rem;font-weight:700}
  /* modern */
  .rc-modern .rc-head{background:linear-gradient(135deg,var(--accent),#2a2a2a);color:#fff;border-radius:10px 10px 0 0;border-bottom:none;padding:1rem}
  .rc-modern .rc-head p{color:rgba(255,255,255,.85)}
  /* compact */
  .rc-compact .rc-table{font-size:.64rem}
  {{-- bordered (Template 7, 2026-07-28): a bold accent frame, for a school that wants a strong branded look rather than a plain minimal sheet. --}}
  .rc-bordered{border:6px solid var(--accent);padding:1.6rem}
  .rc-bordered .rc-head{border-bottom:3px solid var(--accent);padding-bottom:.8rem}
  .rc-bordered .rc-table th{background:var(--accent);color:var(--accent-ink)}
  .rc-bordered .rc-table td,.rc-bordered .rc-table th{border:1.5px solid var(--accent)}
  .rc-bordered .rc-summary .box{border:1.5px solid var(--accent)}
  {{-- vibrant (Template 8, 2026-07-28): a colourful, rounded, friendly sheet suited to Nursery/Primary, distinct from compact's identical style at a smaller size. --}}
  .rc-vibrant .rc-head{background:color-mix(in srgb, var(--accent) 12%, #fff);border-radius:14px 14px 0 0;border-bottom:none;padding:1rem}
  .rc-vibrant .rc-table{border-radius:8px;overflow:hidden}
  .rc-vibrant .rc-table th{background:var(--accent);color:var(--accent-ink)}
  .rc-vibrant .rc-table tbody tr:nth-child(even){background:color-mix(in srgb, var(--accent) 6%, #fff)}
  .rc-vibrant .rc-summary .box{background:color-mix(in srgb, var(--accent) 10%, #fff);border-radius:14px}
  {{-- Same size as the student photo, both bigger than before (user
       request 2026-07-24: "make the school logo bigger than the current
       size and the child image should also have same size"). --}}
  .rc-logo{width:76px;height:76px;object-fit:contain;display:block;margin:0 auto .4rem}
  /* Student Photo layout designer block (2026-07-23) */
  .rc-photo{width:76px;height:76px;object-fit:cover;border-radius:10px;display:block;margin:0 auto .4rem;border:1px solid #d8d8d8;overflow:hidden}
  .rc-photo svg{width:100%;height:100%;display:block}
  .rc-photo-placeholder{display:grid;place-items:center;background:#f3f4f6;color:#888;font-weight:800;font-size:1.15rem}
  /* Layout designer custom header/id blocks (2026-07-18) */
  .rc-head-custom{display:flex;flex-direction:column;align-items:center;gap:.25rem}
  .rc-head-custom .rc-logo{margin-bottom:0}
  /* evaluation / narrative (Templates 5 and 6) */
  /* text-align:center, matching every other template's own header
     (2026-08-15, live report from greenfield: a Nursery class, which
     uses this narrative template, rendered its school name/contact
     block left aligned while every other class rendered centered).
     The base .rc-head rule already centers; this template was the one
     outlier explicitly overriding it back to left. */
  .rc-eval .rc-head{display:flex;align-items:center;gap:.8rem;text-align:center;border-bottom:2px solid var(--accent);padding-bottom:.6rem}
  .rc-eval .rc-head .rc-logo{margin:0;flex:none}
  .rc-eval .rc-head-text{flex:1}
  .rc-eval .rc-head h1{font-size:1.9rem}
  .rc-eval .rc-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;background:#f3f4f6;border-radius:8px;padding:.5rem .6rem;font-size:.7rem;margin-bottom:.8rem}
  .rc-eval .rc-strip div b{display:block;font-size:.58rem;text-transform:uppercase;letter-spacing:.04em;color:#777;font-weight:700}
  .rc-block{margin-bottom:.7rem}
  {{-- Section headers centered, body text justified: the same site wide
       rule every other product screen already follows (CLAUDE.md's Design
       System Rules), not previously applied to a report card's own block
       headers ("Behavioural / Psychomotor Traits", "Subject Comments",
       "Grade Key", etc, all rendered left aligned before this). --}}
  .rc-block h3{font-size:.66rem;text-align:center;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:1px solid #e2e2e2;padding-bottom:.25rem;margin-bottom:.4rem}
  .rc-twocol{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
  {{-- Designer chosen row/column grouping (user request 2026-07-24:
       "insert columns into a section... 1 row by 1 column or the admin
       selects the rows and columns needed"), --rc-cols set inline per
       row from ReportCardLayout::groupIntoRows(). --}}
  .rc-cols{display:grid;grid-template-columns:repeat(var(--rc-cols,2),1fr);gap:1rem}
  .rc-traits{width:100%;border-collapse:collapse;font-size:.68rem}
  .rc-traits td{padding:.22rem 0;border-bottom:1px dashed #e2e2e2}
  .rc-traits td.val{text-align:right;font-weight:700}
  .rc-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:.4rem;font-size:.62rem}
  .rc-legend span{background:#f3f4f6;border-radius:6px;padding:.2rem .45rem}
  .rc-signoff{display:flex;justify-content:space-around;gap:1.2rem;text-align:center;margin-top:1rem;padding-top:.7rem;border-top:1px solid #e2e2e2}
  .rc-signoff img{max-height:44px;max-width:120px;object-fit:contain;display:block;margin:0 auto .25rem}
  .rc-signoff .line{border-top:1px solid #999;margin-top:.3rem;padding-top:.25rem;font-size:.58rem;text-transform:uppercase;letter-spacing:.03em;color:#777}
  .rc-footer{margin-top:.8rem;padding-top:.6rem;border-top:1px solid #e2e2e2;font-size:.66rem;color:#777}
  @media (max-width:640px){ .rc-twocol{grid-template-columns:1fr} .rc-cols{grid-template-columns:1fr} .rc-eval .rc-strip{grid-template-columns:1fr} }
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
    /* Every spacing/font value on this page is set in rem, relative to
       the ROOT element's font-size, not to .rc-sheet's own (found
       2026-07-24 measuring actual rendered heights: an 11 subject SSS
       report with behavioural traits, physical stats, and class teacher
       contact all on came to ~1184px against a page budget of ~1047px,
       even after every individual size below was already reduced once,
       user report: "still spilling into page two... so many spaces that
       needs to be removed, font sizes that are too big"). Scaling the
       root font-size, print only, scales every rem measurement on the
       page together in one place, in proportion, rather than re-tuning
       a dozen individual paddings by trial and error.
    */
    html{font-size:13.5px}
    body{background:#fff}
    .rc-bar,.appbar,.side,.impersonate-bar,.rc-table-hint{display:none !important}
    .content{padding:0 !important}
    .rc-wrap{max-width:none;margin:0;padding:0}
    .rc-sheet{box-shadow:none;border-radius:0;padding:0}
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
    .rc-remarks div{margin-bottom:.35rem}
    .rc-signoff{margin-top:.5rem;padding-top:.4rem}
    .rc-footer{margin-top:.5rem;padding-top:.4rem}
    .rc-signoff img{max-height:36px}
    .rc-logo,.rc-photo{width:44px;height:44px}
    /* Score Trend/Subject Breakdown are opt-in extras on top of an
       already full page (a school adds them deliberately, per this
       catalog's own doc comment: "not mandatory... only if the admin
       asks"), so the honest fix is not chasing zero overflow for every
       possible combination, it's making the two heaviest optional
       blocks reasonably compact when a school does add them. Both SVGs
       use inline max-width:32rem with height:auto (preserves aspect
       ratio), so shrinking the rem value here proportionally shrinks
       height too, print only. */
    .rc-block svg{max-width:19rem !important}
    /* .rc-table itself is deliberately NOT in this list (fixed
       2026-07-23, real user report: a page 1 left almost entirely blank
       below the header on a 24 subject SSS report). break-inside:avoid
       on the table as a whole tells the browser "never start this
       element unless it fits entirely in what's left of the page", so a
       table taller than a full remaining page gets pushed whole onto the
       next page instead of starting right after the header and
       continuing across the page break, exactly like every other
       report card template already does. Only individual rows need to
       stay intact, already covered by .rc-table tr below. */
    .rc-summary,.rc-block,.rc-signoff,.rc-tally,.rc-strip,.rc-footer{break-inside:avoid}
    .rc-table tr{break-inside:avoid}
    .rc-remarks div{break-inside:avoid}
  }
</style>
@endpush

@section('content')
<div class="rc-wrap">
  <div class="rc-bar">
    @if($isPreview)<span class="tag">Preview, sample data</span>@else<span></span>@endif
    <button class="btn btn-ghost btn-sm" onclick="window.print()" type="button">Print</button>
  </div>

  @include('school.report-cards._sheet')
</div>
@endsection
