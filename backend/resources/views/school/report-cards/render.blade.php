@extends('layouts.app', ['accent' => $accent])
@section('title', 'Report Card')
@section('bare', true)

@push('head')
<style>
  body{background:#e9ebee}
  .rc-wrap{max-width:56rem;margin:0 auto;padding:2rem 1.25rem 4rem}
  .rc-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
  .rc-bar .tag{font-size:.75rem;font-weight:700;padding:.3rem .7rem;border-radius:999px;background:var(--accent);color:var(--accent-ink)}
  {{-- A4 proportioned by default (user directive 2026-09-22: "all
       report cards for all schools are going to be on A4 paper size by
       default"), on screen too, not just when printed -- min-height
       only, so a genuinely long report (many subjects, long comments)
       still grows past one page rather than being clipped; the .rc-wrap
       max-width above is the 210mm side of that same 210:297 ratio. --}}
  {{-- z-index:0 added 2026-09-23: position:relative alone does NOT
       establish a new CSS stacking context (only position + an explicit
       z-index does). Without it, .rc-watermark's z-index:-1 below isn't
       contained behind THIS element's own background -- it escapes to
       the nearest ancestor that DOES establish a stacking context,
       which could be far up the tree, rendering the watermark hidden
       under opaque backgrounds all the way up to <body>. This is the
       real, previously-undiagnosed reason the watermark never showed at
       all, on screen or in print, regardless of every opacity/size/
       print-color-adjust fix tried before this. --}}
  .rc-sheet{position:relative;z-index:0;background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12);font-size:.72rem;min-height:79.2rem}
  {{-- Section watermark (2026-09-21): behind everything via a negative
       z-index inside .rc-sheet's own stacking context (position:relative
       above), so no other rule in this file needs to know it exists. --}}
  {{-- Rebuilt 2026-09-23 to match the real reference report card (user's
       own PDF): one large, roughly centered logo image, not a small
       repeating tile -- the earlier tiled 100px pattern at .22 opacity
       was structurally correct but visually nothing like what the
       reference actually shows (one big crest behind the middle of the
       page, faded but still clearly colored/legible). --}}
  .rc-watermark{position:absolute;inset:0;background-repeat:no-repeat;background-position:center 46%;background-size:90%;opacity:.22;z-index:-1;pointer-events:none}
  {{-- Text watermark (2026-09-22, "add a text" alternative to an
       image): one large rotated word across the page, not tiled like
       the logo image above -- a repeating text pattern needs real
       image generation to tile cleanly, a single centered word does
       not. --}}
  .rc-watermark-text{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;overflow:hidden;z-index:-1;pointer-events:none}
  .rc-watermark-text span{font-size:3.4rem;font-weight:800;color:rgba(0,0,0,.07);transform:rotate(-30deg);white-space:nowrap;text-transform:uppercase;letter-spacing:.06em}
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
  {{-- Bumped again, 104px to 130px (user report 2026-09-23, a second
       round: "check the school logo size, increase it" -- still read
       as too small against the reference even after the first bump). --}}
  .rc-logo{width:130px;height:130px;object-fit:contain;display:block;margin:0 auto .4rem}
  {{-- Nudged right off the card's left edge, row-layout heads only
       (user report 2026-09-23: "move it to the right a bit") --
       .rc-head-custom's own column-stack layout already centers its
       logo via the margin:auto above, untouched. --}}
  .rc-head:not(.rc-head-custom) > .rc-logo{margin-left:.6rem}
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
  {{-- Real bordered tables (2026-09-22, reference report card): the
       Attendance/Physical block and the closing Remarks block, every
       template. --}}
  {{-- margin-bottom:0, not .4rem (user report 2026-09-23: "the border
       lines are breaking, should show the borderline across all
       sections" -- the reference's own outer frame runs continuously
       from Attendance/Physical through Key Assessment Areas through
       the closing Remarks, with no visible gap between them; a
       specific table's own spacing need (e.g. the identity strip)
       comes from its own more specific class, see .rc-identity-table
       below). --}}
  {{-- Separate borders, not collapse (user 2026-09-23: "broken border...
       close all the tables bottom border"): with collapsed borders the
       right and bottom edges were being clipped when printed to PDF.
       Here the table draws its own top and left edge and every cell draws
       its own right and bottom edge, so all four outer edges sit inside
       the table box and are always drawn, one line thick everywhere. --}}
  .rc-bordered-table{width:100%;border-collapse:separate;border-spacing:0;border-top:1px solid #444;border-left:1px solid #444;font-size:.68rem;margin-bottom:0}
  {{-- Two consecutive blocks that both hold a bordered table sit flush
       against each other, so their outer borders read as one
       continuous frame instead of two separate boxes with a gap --
       only when connecting to ANOTHER bordered block, so a block
       followed by something else (or the last bordered block on the
       card) keeps its normal breathing room via .rc-block's own
       margin-bottom below. --}}
  .rc-block:has(.rc-bordered-table):has(+ .rc-block .rc-bordered-table){margin-bottom:0}
  {{-- #444, not the original #999 (user report 2026-09-22: "the borderlines also are not correct" -- too light to read as a real grid, matching the reference's own bolder lines). --}}
  .rc-bordered-table th,.rc-bordered-table td{border:0;border-right:1px solid #444;border-bottom:1px solid #444;padding:.3rem .45rem;text-align:left;vertical-align:top}
  {{-- The app-wide stylesheet (layouts/app.blade.php) has
       "tr:last-child td{border-bottom:none}" for its own list tables. It is
       MORE specific than the rule above, so it stripped the bottom border
       off the last row of every report card table (user report
       2026-09-23, "you still did not close the table borders at the
       bottom", found by printing the page to PDF in a real browser).
       This restores it at a higher specificity. --}}
  .rc-bordered-table tr:last-child td,.rc-bordered-table tr:last-child th{border-bottom:1px solid #444}
  {{-- Explicit color/weight, not just relying on the browser's default
       <th> boldness (user report 2026-09-23: header row read as faint,
       almost-invisible gray text in a real downloaded PDF -- the
       reference's own headers are solid bold black). --}}
  .rc-bordered-table th{background:#f3f4f6;color:#1a1a1a;font-weight:700;font-size:.64rem;text-transform:uppercase;letter-spacing:.03em;text-align:center}
  .rc-bordered-table b{text-transform:uppercase;letter-spacing:.02em;font-size:.62rem}
  .rc-identity-table{margin-bottom:.8rem}
  {{-- Narrative / Key Assessment Areas report redesigned to match the
       user's own reference PDF (2026-09-23, "redesign it to match the
       template i gave you"): one bold outer frame around the whole card,
       plain bold black table headers (no gray fill), larger table text,
       bold uppercase identity row, centered figures in the attendance
       and weight/height cells, tall comment rows, and the Remarks table
       pushed to the bottom of the A4 frame (flex column, margin-top:auto)
       so the frame is filled the way the reference is. Scoped to .rc-eval
       so every other template keeps its own look. --}}
  .rc-eval{border:2px solid #000;border-radius:0;padding:1.4rem;display:flex;flex-direction:column;box-sizing:border-box}
  .rc-eval .rc-head{border-bottom:none;padding-bottom:.4rem}
  {{-- Bigger school details, and website / email / phone each on their own row (user 2026-09-23). --}}
  .rc-eval .rc-head h1{font-size:1.9rem}
  .rc-eval .rc-head p{font-size:.95rem;font-weight:600;color:#1a1a1a;margin:.2rem 0 0}
  .rc-eval .rc-head .rc-contact-line{flex-direction:column;align-items:center;gap:.1rem}
  .rc-eval .rc-head .rc-contact-item svg{width:.85rem;height:.85rem}
  .rc-eval .rc-head .term{font-size:1.15rem;font-weight:800;text-transform:uppercase;margin-top:.4rem}
  .rc-eval .rc-bordered-table{font-size:.85rem}
  .rc-eval .rc-bordered-table th{background:transparent;font-size:.85rem;letter-spacing:0;padding:.35rem .5rem}
  .rc-eval .rc-bordered-table td{padding:.4rem .55rem}
  .rc-eval .rc-bordered-table b{font-size:inherit;letter-spacing:0}
  {{-- Name, gender, class and session on ONE row (user 2026-09-23): small
       enough type, and only the name cell may wrap if a real name is long. --}}
  .rc-eval .rc-identity-table td{font-weight:700;font-size:.78rem;text-transform:uppercase;padding:.35rem .4rem}
  .rc-eval .rc-identity-table td:not(:first-child){white-space:nowrap}
  {{-- Logo bigger and right beside the school details, the pair centered together, instead of the logo pinned to the far left. --}}
  .rc-eval .rc-head{justify-content:center;gap:1.1rem}
  .rc-eval .rc-head-text{flex:0 1 auto}
  .rc-eval .rc-head .rc-logo{width:150px;height:150px}
  {{-- Section titles (Physical & Attendance, Key Assessment Areas...) in the same bold black as the Psychomotor / Affective titles (user 2026-09-25: "the Physical & Attendance text has not changed"). --}}
  .rc-eval .rc-block > h3{margin:.55rem 0 .3rem;color:#1a1a1a;font-weight:700;font-size:.85rem;letter-spacing:0;border-bottom:0;padding-bottom:0}
  {{-- The scored Evaluation Sheet (Primary/Secondary) shares this framed look: its scores table and summary boxes take the same closed dark grid and plain bold headers as the tables above. --}}
  .rc-eval .rc-table{border-collapse:separate;border-spacing:0;border-top:1px solid #444;border-left:1px solid #444;font-size:.85rem;margin-bottom:.6rem}
  .rc-eval .rc-table th,.rc-eval .rc-table td{border:0;border-right:1px solid #444;border-bottom:1px solid #444;padding:.4rem .5rem}
  .rc-eval .rc-table th{background:transparent;color:#1a1a1a;font-weight:700;font-size:.8rem;letter-spacing:0;text-align:center}
  .rc-eval .rc-summary .box{background:transparent;border:1px solid #444;border-radius:0}
  {{-- Grouped Cognitive Domain and the Psychomotor / Affective grids (2026-09-24). --}}
  .rc-bordered-table td.num{text-align:center;vertical-align:middle}
  {{-- inline-block, so the sideways label is one box the cell can centre both ways (user 2026-09-24: centre each group label within its rows). --}}
  .rc-vgroup{width:1.7rem;text-align:center;vertical-align:middle;padding:.15rem}
  .rc-vgroup span{display:inline-block;vertical-align:middle;writing-mode:vertical-rl;transform:rotate(180deg);text-align:center;font-weight:700;text-transform:uppercase;font-size:.68rem;letter-spacing:.04em;white-space:nowrap}
  {{-- Attendance and weight/height labels in bold on every report card (user 2026-09-24). --}}
  .rc-attend-physical td.rc-lbl{font-weight:700}
  .rc-eval .rc-cognitive{font-size:.78rem;margin-bottom:.5rem}
  .rc-eval .rc-cognitive th{font-size:.76rem;padding:.25rem .3rem}
  .rc-eval .rc-cognitive td{padding:.18rem .35rem}
  .rc-eval .rc-marks-row th{font-weight:700}
  .rc-domains{display:grid;grid-template-columns:1fr 1.6fr;gap:.7rem;align-items:start}
  .rc-domains .rc-bordered-table{margin-bottom:.5rem}
  .rc-eval .rc-domains td{font-size:.78rem;padding:.18rem .35rem}
  .rc-domains td.num{width:5.4rem;white-space:nowrap}
  {{-- The Psychomotor / Affective grids are the look the school prefers (user 2026-09-25): the Physical & Attendance and Cognitive Domain tables now use the same title size, compact rows, bold capital labels and plain values. --}}
  .rc-bordered-table td.rc-vgroup{vertical-align:middle;text-align:center}
  .rc-eval .rc-cognitive th.rc-title{font-size:.85rem;padding:.35rem .5rem}
  .rc-eval .rc-attend-physical td,.rc-eval .rc-attend-physical .rc-subhead th{font-size:.78rem;padding:.18rem .35rem;text-transform:none}
  .rc-eval .rc-attend-physical .rc-subhead th{text-transform:uppercase}
  .rc-eval .rc-attend-physical td.rc-lbl{text-transform:uppercase}
  {{-- Subject names and trait names in bold capitals, as on the school's own sheet (user 2026-09-24). --}}
  .rc-cognitive td.rc-subj,.rc-domains td.rc-trait{font-weight:700;text-transform:uppercase}
  .rc-bordered-table td.rc-num{text-align:center;vertical-align:middle}
  .rc-attend-physical .rc-subhead th{font-weight:700}
  .rc-eval .rc-attend-physical .rc-subhead th{font-weight:700;text-transform:none;font-size:.85rem}
  .rc-eval .rc-remarks-table{margin-top:auto}
  .rc-eval .rc-remarks-table td{font-weight:700;height:3rem}
  .rc-eval .rc-remarks-table tr:last-child td{height:5.5rem;vertical-align:bottom}
  .rc-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:.4rem;font-size:.62rem}
  .rc-legend span{background:#f3f4f6;border-radius:6px;padding:.2rem .45rem}
  .rc-signoff{display:flex;justify-content:space-around;gap:1.2rem;text-align:center;margin-top:1rem;padding-top:.7rem;border-top:1px solid #e2e2e2}
  .rc-signoff img{max-height:44px;max-width:120px;object-fit:contain;display:block;margin:0 auto .25rem}
  .rc-signoff .line{border-top:1px solid #999;margin-top:.3rem;padding-top:.25rem;font-size:.58rem;text-transform:uppercase;letter-spacing:.03em;color:#777}
  .rc-footer{margin-top:.8rem;padding-top:.6rem;border-top:1px solid #e2e2e2;font-size:.66rem;color:#777}
  @media (max-width:640px){ .rc-twocol{grid-template-columns:1fr} .rc-cols{grid-template-columns:1fr} }
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
    /* Browsers strip background-image/background-color by default when
       printing or "Save as PDF" (an ink-saving default) unless a page
       explicitly opts in with print-color-adjust -- this is the real
       root cause of the watermark never appearing in any printed/PDF
       output regardless of how its opacity/tile size were tuned (user
       report 2026-09-23: a real downloaded PDF with no watermark at all
       despite the single-large-image rebuild two commits earlier).
       Applied broadly (not just .rc-watermark) since the header row's
       light gray background and the preview tag badge rely on the same
       browser default too. */
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;color-adjust:exact !important}
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
    {{-- min-height:auto here specifically: the real @page{size:A4} rule
         above already governs actual print pagination, a min-height
         meant for the on-screen preview would otherwise risk pushing a
         short report onto a needless second blank printed page. --}}
    .rc-sheet{box-shadow:none;border-radius:0;padding:0;min-height:auto}
    {{-- Narrative report fills one A4 page (user 2026-09-23: "make the
         report card fit into the a4 paper size"): 262mm is under the
         277mm printable height (@page margins), so it can never tip onto
         a second blank page; a longer report still grows naturally. --}}
    .rc-eval{padding:.7rem .9rem;min-height:262mm;border:2px solid #000}
    .rc-eval .rc-bordered-table,.rc-eval .rc-bordered-table th{font-size:1rem}
    .rc-eval .rc-bordered-table td{padding:.3rem .5rem}
    .rc-eval .rc-identity-table td{font-size:.85rem}
    .rc-eval .rc-table{font-size:.95rem}
    .rc-eval .rc-table th{font-size:.85rem}
    .rc-eval .rc-table th,.rc-eval .rc-table td{padding:.28rem .45rem}
    .rc-eval .rc-cognitive{font-size:.76rem}
    .rc-eval .rc-cognitive th{font-size:.76rem;padding:.12rem .3rem}
    .rc-eval .rc-cognitive td{padding:.06rem .3rem}
    .rc-eval .rc-cognitive th.rc-title{font-size:1rem;padding:.3rem .5rem}
    .rc-eval .rc-block > h3{font-size:1rem}
    .rc-eval .rc-domains td{font-size:.76rem;padding:.06rem .3rem}
    .rc-eval .rc-attend-physical td,.rc-eval .rc-attend-physical .rc-subhead th{font-size:.76rem;padding:.06rem .3rem}
    {{-- A sheet carrying the full grouped subject table needs shorter comment rows to stay on one A4 page. --}}
    .rc-eval:has(.rc-cognitive) .rc-remarks-table td{height:2rem}
    .rc-eval:has(.rc-cognitive) .rc-remarks-table tr:last-child td{height:3.4rem}
    .rc-eval .rc-head .rc-logo{width:118px;height:118px}
    .rc-eval .rc-remarks-table td{height:2.6rem}
    .rc-eval .rc-remarks-table tr:last-child td{height:4.5rem}
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
    {{-- Was unconditionally 44px, silently undoing every on-screen
       .rc-logo size bump above for print/PDF specifically -- since
       "preview and download" always renders through this print
       stylesheet, this is very likely why the logo never looked bigger
       across multiple size increases (user report 2026-09-23). Kept
       print-budget-conscious (smaller than the 130px screen size) but
       genuinely bigger than before. --}}
    .rc-photo{width:44px;height:44px}
    .rc-logo{width:92px;height:92px}
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
    .rc-bordered-table tr{break-inside:avoid}
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
