@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Layout Designer'])
@section('title', 'Report Card Layout Designer')

@push('head')
<style>
  /* Real report card markup, copied from report-cards/render.blade.php
     so what is dragged here IS what prints, never a lookalike mockup. */
  .lb-preview-wrap{background:#e9ebee;border-radius:12px;padding:1.75rem 1.25rem}
  .rc-sheet{background:#fff;color:#1a1a1a;border-radius:10px;padding:2rem;box-shadow:0 8px 30px rgba(0,0,0,.12);max-width:38rem;margin:0 auto}
  .rc-head{text-align:center;border-bottom:3px solid var(--accent);padding-bottom:1rem;margin-bottom:1rem;display:flex;flex-direction:column;gap:.3rem}
  .rc-head h1{font-size:1.4rem;font-weight:800}
  .rc-head p{font-size:.85rem;color:#555}
  .rc-contact-line{display:flex;flex-wrap:wrap;justify-content:center;gap:.15rem .8rem}
  .rc-contact-item{display:inline-flex;align-items:center;gap:.25rem}
  .rc-contact-item svg{width:.75rem;height:.75rem;flex-shrink:0}
  .rc-head .term{font-size:.95rem;font-weight:700;margin-top:.4rem;text-transform:uppercase;letter-spacing:.04em}
  /* 2 columns here vs the real render's 4 (render.blade.php): this
     designer canvas is deliberately narrower (a sidebar sits beside it),
     4 columns at that width would crush the labels unreadably. Same
     block content and order either way, only the wrap width differs. */
  .rc-id{display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem;font-size:.82rem;margin-bottom:1.1rem}
  .rc-id div b{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:#777;font-weight:700}
  .rc-logo{width:60px;height:60px;object-fit:contain;display:block;margin:0 auto .5rem}
  .rc-head-custom .rc-logo{margin-bottom:0}
  .rc-table{width:100%;border-collapse:collapse;font-size:.78rem;margin-bottom:.6rem}
  .rc-table th,.rc-table td{border:1px solid #d8d8d8;padding:.4rem .45rem;text-align:left}
  .rc-table th{background:#f3f4f6;font-size:.66rem;text-transform:uppercase}
  .rc-table td.num,.rc-table th.num{text-align:center}
  .rc-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(5rem,1fr));gap:.5rem;margin-bottom:.6rem}
  .rc-summary .box{background:#f7f7f8;border-radius:8px;padding:.5rem;text-align:center}
  .rc-summary .box b{font-size:.95rem;display:block}
  .rc-summary .box span{font-size:.6rem;text-transform:uppercase;color:#777;font-weight:700}
  .rc-remarks div{margin-bottom:.5rem;font-size:.78rem;text-align:center}
  .rc-remarks b{display:block;font-size:.65rem;text-transform:uppercase;color:#777;margin-bottom:.1rem}
  .rc-signoff{display:flex;justify-content:space-around;gap:1rem;text-align:center;margin-top:1rem;padding-top:.8rem;border-top:1px solid #e2e2e2;font-size:.72rem}
  .rc-signoff img{max-height:40px;max-width:100px;object-fit:contain;display:block;margin:0 auto .2rem}
  .rc-block{margin-bottom:.8rem}
  .rc-block > h3{font-size:.7rem;text-transform:uppercase;color:#555;border-bottom:1px solid #e2e2e2;padding-bottom:.25rem;margin-bottom:.4rem}
  .rc-twocol{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
  .rc-traits{width:100%;border-collapse:collapse;font-size:.75rem}
  .rc-traits td{padding:.25rem 0;border-bottom:1px dashed #e2e2e2}
  {{-- Real bordered tables (2026-09-22, reference report card): the
       Attendance/Physical block and the closing Remarks block, every
       template. --}}
  .rc-bordered-table{width:100%;border-collapse:collapse;font-size:.75rem;margin-bottom:.4rem}
  .rc-bordered-table th,.rc-bordered-table td{border:1px solid #999;padding:.3rem .45rem;text-align:left;vertical-align:top}
  .rc-bordered-table th{background:#f3f4f6;font-size:.62rem;text-transform:uppercase;letter-spacing:.03em;text-align:center}
  .rc-remarks-table b{text-transform:uppercase;letter-spacing:.02em;font-size:.65rem}
  .rc-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:.4rem;font-size:.68rem}
  .rc-legend span{background:#f3f4f6;border-radius:6px;padding:.2rem .5rem}
  .rc-tally{display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.6rem}
  .rc-tally span{background:#f3f4f6;border-radius:6px;padding:.2rem .5rem;font-size:.68rem;font-weight:700}

  /* Designer overlay on top of the real markup above. */
  .lb-zone{border-radius:8px;transition:outline-color .15s;outline:2px dashed transparent;outline-offset:6px}
  .lb-zone.drag-over{outline-color:var(--accent)}
  .lb-zone:empty::before{content:"Drag a block here, or tap + on a block in the panel";display:block;font-size:.72rem;color:#aaa;font-style:italic;padding:.6rem;text-align:center;border:1px dashed #ddd;border-radius:8px}
  .lb-block{cursor:grab;border-radius:5px;outline:1px dashed transparent;outline-offset:3px}
  .lb-block:hover{outline-color:#ccc}
  .lb-block.selected{outline:2px solid var(--accent);outline-offset:3px;background:color-mix(in srgb,var(--accent) 6%,transparent)}
  .lb-block.dragging{opacity:.35}
  {{-- A single 6 column auto flow grid for the whole body zone (user
       request 2026-07-24: "let the report card designer also allow one
       to insert columns into a section... so one can have items side
       by side... let the report designer in view match the current
       live report card view"). A section's own grid-column span (set
       inline per element from its columns choice: full width=6,
       half=3, third=2) decides how much of the row it takes; the
       browser's own grid auto placement naturally packs two half width
       sections into one row and starts a full width one on its own,
       with no JS needed to detect or rebuild "rows" as the admin drags
       things around. --}}
  #zone-body.lb-zone{display:grid;grid-template-columns:repeat(6,1fr);gap:.7rem;align-items:start}
  .lb-section{cursor:grab;position:relative;border-radius:6px;outline:1px dashed transparent;outline-offset:4px;padding:.4rem;margin:1rem -.4rem 0;grid-column:span 6}
  .lb-section:hover{outline-color:#ccc}
  .lb-section.selected{outline:2px solid var(--accent);outline-offset:4px;background:color-mix(in srgb,var(--accent) 5%,transparent)}
  .lb-section.dragging{opacity:.35}
  {{-- A small floating corner chip, not a full width line stacked
       directly above the block's own content (user report 2026-07-24:
       "it has duplicated items on the report card designer now" —
       every section's own heading, e.g. "Class Teacher Contact", sat
       directly under this same label in the same small caps style,
       reading as the same text twice). This is designer only chrome
       (identifies which block this is while dragging/selecting), never
       part of the real report card, so it should look like editor UI,
       not report content. --}}
  .lb-section-tag{position:absolute;top:-.55rem;left:.5rem;background:var(--accent);color:var(--accent-ink);font-size:.56rem;text-transform:uppercase;letter-spacing:.03em;font-weight:800;padding:.12rem .5rem;border-radius:999px;z-index:2;pointer-events:none}

  .lb-shell{display:grid;grid-template-columns:1fr 17rem;gap:1.25rem;align-items:start;margin-top:1.1rem}
  @media(max-width:860px){.lb-shell{grid-template-columns:1fr}}
  .lb-toolbar{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;min-height:2.5rem;padding:.5rem .7rem;background:var(--bg);border:1px solid var(--line);border-radius:10px;margin-bottom:.9rem;font-size:.82rem}
  .lb-toolbar .hint{color:var(--mut-dim);font-style:italic}
  .lb-toolbar .btn{padding:.3rem .6rem}
  .lb-toolbar .align-group{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
  .lb-toolbar .align-group button{padding:.3rem .55rem;border:none;background:var(--panel);cursor:pointer;font-size:.78rem}
  .lb-toolbar .align-group button.active{background:var(--accent);color:var(--accent-ink)}
  .lb-tray-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);margin-bottom:.6rem;margin-top:1.4rem}
  .lb-tray-title:first-child{margin-top:0}
  .lb-tray-item{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:.5rem .6rem;margin-bottom:.5rem;cursor:grab}
  .lb-tray-item:last-child{margin-bottom:0}
  .lb-tray-item .lb-tray-label{font-weight:600;font-size:.82rem;display:block;margin-bottom:.35rem}
  .lb-tray-item .lb-tray-add{display:flex;gap:.3rem;flex-wrap:wrap}
  .lb-tray-item .lb-tray-add button{font-size:.7rem;padding:.25rem .5rem}
  .lb-tray-empty{color:var(--mut-dim);font-size:.78rem;font-style:italic}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Layout Designer</h1>
    <p class="subtitle">The whole report card, live. Drag a block or a whole section onto the page, drag them around to reorder, drag one off to remove it. Select a header block to align it left, center, or right.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.edit', ['school' => $school->slug]) }}">Back to Report settings</a>
</div>

{{--
  "Editing for" a real class or section, not just a read only preview
  (user directive 2026-09-21, confirmed via AskUserQuestion: "A
  genuinely different arrangement per class/section"). Every block/
  section below is re-rendered server side (on submit) with that class
  or section's own resolved template, narrative style, watermark, and
  real Key Assessment Areas, using dummy student data. Left on "Whole
  school (default layout)", this page behaves exactly as before this
  existed -- the school wide flat layout, unchanged.
--}}
@if($sectionsForPicker->isNotEmpty())
<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0;min-width:16rem">
      <label for="lb_target">Editing for</label>
      <select id="lb_target" name="target" onchange="this.form.requestSubmit()">
        <option value="" @selected($editingScope === 'school')>Whole school (default layout)</option>
        @foreach($sectionsForPicker as $section)
          <optgroup label="{{ $section->name }}">
            @if($canEditSections)
              <option value="section:{{ $section->id }}" @selected($editingScope === 'section' && $editingSection?->id === $section->id)>{{ $section->name }} (whole section)</option>
            @endif
            @foreach($section->classes as $class)
              <option value="class:{{ $class->id }}" @selected($editingScope === 'class' && $editingClass?->id === $class->id)>{{ $class->name }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
    </div>
    @if($previewClass)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.preview.class', ['school' => $school->slug, 'classId' => $previewClass->id]) }}" target="_blank" rel="noopener">Open full preview</a>
    @endif
  </form>

  @if($editingScope !== 'school')
    <p class="muted" style="margin-top:.7rem">
      @if($hasOwnOverride)
        Editing {{ $editingClass?->name ?? $editingSection?->name }}'s own custom layout, separate from the school default.
      @else
        Showing the layout {{ $editingClass?->name ?? $editingSection?->name }} currently inherits. Saving below starts a custom layout just for {{ $editingScope === 'class' ? 'this class' : 'this section' }}, the school default is untouched.
      @endif
    </p>
    @if($hasOwnOverride)
      <form method="POST" action="{{ route('school.report-cards.layout.reset-override', ['school' => $school->slug]) }}" style="margin-top:.5rem" onsubmit="return confirm('Remove this custom layout and go back to the inherited default?')">
        @csrf
        <input type="hidden" name="target_scope" value="{{ $editingScope }}" />
        <input type="hidden" name="target_id" value="{{ $editingClass?->id ?? $editingSection?->id }}" />
        <button class="btn btn-ghost btn-sm" type="submit">Follow the inherited default instead</button>
      </form>
    @endif
  @endif
</div>
@endif

<form method="POST" action="{{ route('school.report-cards.layout.update', ['school' => $school->slug]) }}" id="layoutForm">
  @csrf
  <input type="hidden" name="target_scope" value="{{ $editingScope }}" />
  <input type="hidden" name="target_id" value="{{ $editingClass?->id ?? $editingSection?->id }}" />
  <input type="hidden" name="enabled" id="enabledInput" value="{{ $enabled ? '1' : '0' }}" />
  <div id="hiddenBlockInputs"></div>

  <div class="card" style="margin-bottom:1.1rem">
    <label style="display:flex;align-items:center;gap:.6rem;margin:0">
      <input type="checkbox" id="enabledCheckbox" @checked($enabled) />
      <span>Use this custom layout instead of the active template's fixed arrangement</span>
    </label>
    <p class="muted" style="margin-top:.4rem">Turned off, every template renders exactly as designed, nothing below has any effect. Turned on, the arrangement below (header, info strip, and every section) replaces it on every template.</p>
  </div>

  <div class="lb-toolbar" id="toolbar">
    <span class="hint" id="toolbarHint">Tap a block or section below to select it, or drag directly.</span>
  </div>

  <div class="lb-shell">
    <div class="lb-preview-wrap">
      <div class="rc-sheet">
        <div class="rc-head rc-head-custom lb-zone" data-zone="header" id="zone-header">
          @foreach($zones['header'] ?? [] as $key)
            @if(isset($blockHtml[$key]))
              <div class="lb-block" draggable="true" data-block="{{ $key }}" data-align="{{ $headerAlign[$key] ?? 'center' }}" tabindex="0" role="button" aria-label="{{ $blocks[$key] ?? $key }}, select to edit" style="align-self:{{ ['left'=>'flex-start','center'=>'center','right'=>'flex-end'][$headerAlign[$key] ?? 'center'] }}">{!! $blockHtml[$key] !!}</div>
            @endif
          @endforeach
        </div>
        <div class="rc-id lb-zone" data-zone="info" id="zone-info">
          @foreach($zones['info'] ?? [] as $key)
            @if(isset($blockHtml[$key]))
              <div class="lb-block" draggable="true" data-block="{{ $key }}" tabindex="0" role="button" aria-label="{{ $blocks[$key] ?? $key }}, select to edit">{!! $blockHtml[$key] !!}</div>
            @endif
          @endforeach
        </div>
        <div class="lb-zone" data-zone="body" id="zone-body">
          @foreach($bodySections as $key)
            @if(isset($bodyHtml[$key]))
              @php
                $cols = max(1, min(3, ($bodyColumns ?? [])[$key] ?? 1));
                $span = intdiv(6, $cols);
                $align = ($bodyAlign ?? [])[$key] ?? 'stretch';
                // A shared row's own width already comes from its
                // grid-column span, left/center/right alignment (a
                // 75% inset on an otherwise full width section) would
                // just fight it, so columns > 1 wins.
                $alignStyle = $cols > 1 || $align === 'stretch' ? '' : 'max-width:75%;'.['left' => 'margin-right:auto', 'right' => 'margin-left:auto', 'center' => 'margin-left:auto;margin-right:auto'][$align];
              @endphp
              <div class="lb-section" draggable="true" data-section="{{ $key }}" data-align="{{ $align }}" data-columns="{{ $cols }}" tabindex="0" role="button" aria-label="{{ $bodyLabels[$key] ?? $key }}, select to edit" style="grid-column:span {{ $span }};{{ $alignStyle }}">
                <div class="lb-section-tag">{{ $bodyLabels[$key] ?? $key }}</div>
                {!! $bodyHtml[$key] !!}
              </div>
            @endif
          @endforeach
        </div>
      </div>
    </div>

    <div>
      <div class="lb-tray-title">Available blocks</div>
      <div class="lb-zone" data-zone="tray" id="zone-tray">
        @php
          $placed = array_merge($zones['header'] ?? [], $zones['info'] ?? []);
          $unused = array_diff(array_keys($blocks), $placed);
        @endphp

        @forelse($unused as $key)
          <div class="lb-tray-item" draggable="true" data-block="{{ $key }}">
            <span class="lb-tray-label">{{ $blocks[$key] }}</span>
            <div class="lb-tray-add">
              <button type="button" class="btn btn-ghost btn-sm" data-add-to="header">+ Header</button>
              <button type="button" class="btn btn-ghost btn-sm" data-add-to="info">+ Info Strip</button>
            </div>
          </div>
        @empty
          <p class="lb-tray-empty" id="fieldTrayEmpty">Every block is already placed.</p>
        @endforelse
      </div>

      <div class="lb-tray-title">Available sections</div>
      <div class="lb-zone" data-zone="bodyTray" id="zone-bodyTray">
        @php
          $unusedSections = array_diff(array_keys($bodyLabels), $bodySections);
        @endphp

        @forelse($unusedSections as $key)
          <div class="lb-tray-item" draggable="true" data-section="{{ $key }}">
            <span class="lb-tray-label">{{ $bodyLabels[$key] }}</span>
            <div class="lb-tray-add">
              <button type="button" class="btn btn-ghost btn-sm" data-add-section>+ Add to page</button>
            </div>
          </div>
        @empty
          <p class="lb-tray-empty" id="sectionTrayEmpty">Every section is already on the page.</p>
        @endforelse
      </div>
    </div>
  </div>

  <button class="btn btn-primary" type="submit" style="margin-top:1.1rem">
    @if($editingScope === 'school')
      Save layout
    @else
      Save custom layout for {{ $editingClass?->name ?? $editingSection?->name }}
    @endif
  </button>
</form>

<div class="card" style="margin-top:1.1rem">
  <h3 style="margin-bottom:.3rem">Custom text blocks</h3>
  <p class="muted" style="margin-bottom:.8rem">Your own free text, an address, a disclaimer, a scholarship note, anything the fixed fields above do not cover. Once added, it behaves like any other section, drag it onto the page from "Available sections".</p>

  @if($customBlocks->isNotEmpty())
    <div style="display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem">
      @foreach($customBlocks as $block)
        <div style="border:1px solid var(--line);border-radius:8px;padding:.6rem .8rem">
          <div class="row-between" style="align-items:flex-start">
            <div>
              <strong>{{ $block->label }}</strong>
              <p class="muted" style="margin-top:.2rem;white-space:pre-line">{{ \Illuminate\Support\Str::limit($block->content, 140) }}</p>
            </div>
            <div style="display:flex;gap:.4rem;white-space:nowrap">
              <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('edit-block-{{ $block->id }}').classList.toggle('show')">Edit</button>
              <form method="POST" action="{{ route('school.report-cards.layout.custom-blocks.destroy', ['school' => $school->slug, 'blockId' => $block->id]) }}" onsubmit="return confirm('Remove {{ $block->label }}? It will also come off the report card if placed.')">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
              </form>
            </div>
          </div>
          <form id="edit-block-{{ $block->id }}" class="lb-block-edit-row" method="POST" action="{{ route('school.report-cards.layout.custom-blocks.update', ['school' => $school->slug, 'blockId' => $block->id]) }}" style="display:none;gap:.5rem;margin-top:.6rem;flex-direction:column">
            @csrf
            <input name="label" value="{{ $block->label }}" placeholder="Label" required maxlength="80" />
            <textarea name="content" rows="3" placeholder="Text" required maxlength="2000">{{ $block->content }}</textarea>
            <button class="btn btn-primary btn-sm" type="submit" style="align-self:flex-start">Save</button>
          </form>
        </div>
      @endforeach
    </div>
  @endif

  <form method="POST" action="{{ route('school.report-cards.layout.custom-blocks.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
    @csrf
    {{-- The Label and Text fields are wrapped in their own inner flex
         row, aligned to flex-start (their own tops, i.e. their labels),
         rather than sharing the outer row's flex-end — a taller
         textarea sibling next to a plain input under a shared
         align-items:flex-end pushes the SHORTER field's whole block
         upward to match the textarea's bottom, visibly misaligning the
         two labels (user report 2026-08-13, with a screenshot). The
         outer row still bottom-aligns the Add block button against the
         group's own (now correctly-aligned) height. --}}
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start;flex:1;min-width:20rem">
      <div class="field" style="margin:0;min-width:12rem">
        <label for="newBlockLabel">Label</label>
        <input id="newBlockLabel" name="label" placeholder="e.g. School Address" required maxlength="80" />
      </div>
      <div class="field" style="margin:0;flex:1;min-width:16rem">
        <label for="newBlockContent">Text</label>
        <textarea id="newBlockContent" name="content" rows="2" placeholder="e.g. 12 Freedom Way, Lekki, Lagos" required maxlength="2000"></textarea>
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add block</button>
  </form>
</div>

<style>
  .lb-block-edit-row.show{display:flex !important}
</style>

@php
  $blocksJson = collect($blocks)->keys()->mapWithKeys(fn ($k) => [$k => $blockHtml[$k] ?? ''])->all();
  $sectionsJson = collect($bodyLabels)->keys()->mapWithKeys(fn ($k) => [$k => $bodyHtml[$k] ?? ''])->all();
@endphp
<script>
(function(){
  var blockLabels = @json($blocks);
  var blockHtml = @json($blocksJson);
  var sectionLabels = @json($bodyLabels);
  var sectionHtml = @json($sectionsJson);
  var zones = {
    header: document.getElementById('zone-header'), info: document.getElementById('zone-info'), tray: document.getElementById('zone-tray'),
    body: document.getElementById('zone-body'), bodyTray: document.getElementById('zone-bodyTray'),
  };
  var toolbar = document.getElementById('toolbar');
  var toolbarHint = document.getElementById('toolbarHint');
  var selected = null;
  var selectedKind = null; // 'field' or 'section'

  function trayItemHtml(key, kind){
    var el = document.createElement('div');
    el.className = 'lb-tray-item';
    el.draggable = true;
    if (kind === 'section') { el.dataset.section = key; } else { el.dataset.block = key; }
    var addHtml = kind === 'section'
      ? '<button type="button" class="btn btn-ghost btn-sm" data-add-section>+ Add to page</button>'
      : '<button type="button" class="btn btn-ghost btn-sm" data-add-to="header">+ Header</button><button type="button" class="btn btn-ghost btn-sm" data-add-to="info">+ Info Strip</button>';
    el.innerHTML = '<span class="lb-tray-label"></span><div class="lb-tray-add">'+addHtml+'</div>';
    el.querySelector('.lb-tray-label').textContent = (kind === 'section' ? sectionLabels[key] : blockLabels[key]) || key;
    return el;
  }

  function blockHtmlEl(key, align){
    var el = document.createElement('div');
    el.className = 'lb-block';
    el.draggable = true;
    el.tabIndex = 0;
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', (blockLabels[key] || key) + ', select to edit');
    el.dataset.block = key;
    el.dataset.align = align || 'center';
    el.style.alignSelf = align === 'left' ? 'flex-start' : (align === 'right' ? 'flex-end' : 'center');
    el.innerHTML = blockHtml[key] || '';
    return el;
  }

  function sectionAlignStyle(align, columns){
    // A shared row's own width already comes from its grid-column span
    // below, left/center/right (a 75% inset) would just fight it.
    if (columns > 1 || align === 'stretch' || ! align) { return ''; }
    return 'max-width:75%;' + ({ left: 'margin-right:auto', right: 'margin-left:auto', center: 'margin-left:auto;margin-right:auto' }[align] || '');
  }

  // Full width=6, half=3, third=2, out of the 6 column grid #zone-body
  // itself declares (user request 2026-07-24: "insert columns into a
  // section... 1 row by 1 column or the admin selects the rows and
  // columns needed"). The browser's own grid auto placement packs two
  // half width sections into one row with no JS row tracking needed.
  function sectionColumnSpan(columns){
    return 6 / (columns || 1);
  }

  function sectionHtmlEl(key, align, columns){
    var el = document.createElement('div');
    el.className = 'lb-section';
    el.draggable = true;
    el.tabIndex = 0;
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', (sectionLabels[key] || key) + ', select to edit');
    el.dataset.section = key;
    el.dataset.align = align || 'stretch';
    el.dataset.columns = columns || 1;
    el.style.cssText = 'grid-column:span ' + sectionColumnSpan(columns || 1) + ';' + sectionAlignStyle(align || 'stretch', columns || 1);
    el.innerHTML = '<div class="lb-section-tag"></div>' + (sectionHtml[key] || '');
    el.querySelector('.lb-section-tag').textContent = sectionLabels[key] || key;
    return el;
  }

  function refreshTrayEmptyState(zoneKey, itemSelector, msg, id){
    var zone = zones[zoneKey];
    var has = zone.querySelector(itemSelector);
    var existing = document.getElementById(id);
    if (! has && ! existing) {
      var p = document.createElement('p');
      p.className = 'lb-tray-empty';
      p.id = id;
      p.textContent = msg;
      zone.appendChild(p);
    } else if (has && existing) {
      existing.remove();
    }
  }

  function moveField(key, targetZone, before){
    var existing = document.querySelector('.lb-block[data-block="'+key+'"]');
    var currentAlign = existing ? (existing.dataset.align || 'center') : 'center';
    document.querySelectorAll('[data-block="'+key+'"]').forEach(function(n){ n.remove(); });
    var node = targetZone === 'tray' ? trayItemHtml(key, 'field') : blockHtmlEl(key, targetZone === 'header' ? currentAlign : null);
    var target = zones[targetZone];
    if (before) { target.insertBefore(node, before); } else { target.appendChild(node); }
    refreshTrayEmptyState('tray', '.lb-tray-item', 'Every block is already placed.', 'fieldTrayEmpty');
    if (targetZone !== 'tray') { selectItem(node, 'field'); }
  }

  function moveSection(key, targetZone, before){
    var existingSection = document.querySelector('.lb-section[data-section="'+key+'"]');
    var currentAlign = existingSection ? (existingSection.dataset.align || 'stretch') : 'stretch';
    var currentColumns = existingSection ? parseInt(existingSection.dataset.columns || '1', 10) : 1;
    document.querySelectorAll('[data-section="'+key+'"]').forEach(function(n){ n.remove(); });
    var node = targetZone === 'bodyTray' ? trayItemHtml(key, 'section') : sectionHtmlEl(key, currentAlign, currentColumns);
    var target = zones[targetZone];
    if (before) { target.insertBefore(node, before); } else { target.appendChild(node); }
    refreshTrayEmptyState('bodyTray', '.lb-tray-item', 'Every section is already on the page.', 'sectionTrayEmpty');
    if (targetZone !== 'bodyTray') { selectItem(node, 'section'); }
  }

  function clearSelection(){
    document.querySelectorAll('.lb-block.selected, .lb-section.selected').forEach(function(n){ n.classList.remove('selected'); });
    selected = null; selectedKind = null;
    renderToolbar();
  }

  function selectItem(node, kind){
    clearSelection();
    node.classList.add('selected');
    selected = node; selectedKind = kind;
    renderToolbar();
  }

  function renderToolbar(){
    toolbar.innerHTML = '';
    if (! selected) {
      toolbar.appendChild(toolbarHint);
      toolbarHint.textContent = 'Tap a block or section above to select it, or drag directly.';
      return;
    }

    if (selectedKind === 'field') {
      var key = selected.dataset.block;
      var zoneKey = selected.closest('.lb-zone').dataset.zone;
      var label = document.createElement('span');
      label.innerHTML = '<strong>' + (blockLabels[key] || key) + '</strong>';
      toolbar.appendChild(label);

      if (zoneKey === 'header') {
        var group = document.createElement('span');
        group.className = 'align-group';
        ['left', 'center', 'right'].forEach(function(a){
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = a.charAt(0).toUpperCase() + a.slice(1);
          if ((selected.dataset.align || 'center') === a) { b.classList.add('active'); }
          b.addEventListener('click', function(){
            selected.dataset.align = a;
            selected.style.alignSelf = a === 'left' ? 'flex-start' : (a === 'right' ? 'flex-end' : 'center');
            renderToolbar();
          });
          group.appendChild(b);
        });
        toolbar.appendChild(group);
      }

      var up = document.createElement('button'); up.type = 'button'; up.className = 'btn btn-ghost btn-sm'; up.textContent = 'Move up';
      up.addEventListener('click', function(){
        var prev = selected.previousElementSibling;
        if (prev && (prev.classList.contains('lb-block'))) { selected.parentNode.insertBefore(selected, prev); selectItem(selected, 'field'); }
      });
      toolbar.appendChild(up);

      var down = document.createElement('button'); down.type = 'button'; down.className = 'btn btn-ghost btn-sm'; down.textContent = 'Move down';
      down.addEventListener('click', function(){
        var next = selected.nextElementSibling;
        if (next) { selected.parentNode.insertBefore(next, selected); selectItem(selected, 'field'); }
      });
      toolbar.appendChild(down);

      if (zoneKey === 'header' || zoneKey === 'info') {
        var otherZone = zoneKey === 'header' ? 'info' : 'header';
        var move = document.createElement('button'); move.type = 'button'; move.className = 'btn btn-ghost btn-sm';
        move.textContent = 'Move to ' + (otherZone === 'header' ? 'Header' : 'Info Strip');
        move.addEventListener('click', function(){ moveField(key, otherZone); });
        toolbar.appendChild(move);
      }

      var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-ghost btn-sm'; remove.style.color = 'var(--bad)'; remove.textContent = 'Remove';
      remove.addEventListener('click', function(){ moveField(key, 'tray'); clearSelection(); });
      toolbar.appendChild(remove);
    } else {
      var skey = selected.dataset.section;
      var slabel = document.createElement('span');
      slabel.innerHTML = '<strong>' + (sectionLabels[skey] || skey) + '</strong>';
      toolbar.appendChild(slabel);

      var scolumns = parseInt(selected.dataset.columns || '1', 10);

      function applySectionStyle(){
        selected.style.cssText = 'grid-column:span ' + sectionColumnSpan(parseInt(selected.dataset.columns || '1', 10)) + ';' + sectionAlignStyle(selected.dataset.align || 'stretch', parseInt(selected.dataset.columns || '1', 10));
      }

      var sgroup = document.createElement('span');
      sgroup.className = 'align-group';
      ['stretch', 'left', 'center', 'right'].forEach(function(a){
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = a === 'stretch' ? 'Full width' : (a.charAt(0).toUpperCase() + a.slice(1));
        if ((selected.dataset.align || 'stretch') === a) { b.classList.add('active'); }
        if (scolumns > 1) { b.disabled = true; b.title = 'Not available while sharing a row'; }
        b.addEventListener('click', function(){
          selected.dataset.align = a;
          applySectionStyle();
          renderToolbar();
        });
        sgroup.appendChild(b);
      });
      toolbar.appendChild(sgroup);

      {{-- Columns (user request 2026-07-24: "insert columns into a
           section... 1 row by 1 column or the admin selects the rows
           and columns needed"). Two consecutive sections both set to
           the same column count share one row, via #zone-body's own
           grid auto placement, no explicit "pair with" pointer needed. --}}
      var cgroup = document.createElement('span');
      cgroup.className = 'align-group';
      cgroup.title = 'How many sections share this row';
      [1, 2, 3].forEach(function(n){
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = n === 1 ? '1 column' : n + ' columns';
        if (scolumns === n) { b.classList.add('active'); }
        b.addEventListener('click', function(){
          selected.dataset.columns = n;
          if (n > 1) { selected.dataset.align = 'stretch'; }
          applySectionStyle();
          renderToolbar();
        });
        cgroup.appendChild(b);
      });
      toolbar.appendChild(cgroup);

      var sup = document.createElement('button'); sup.type = 'button'; sup.className = 'btn btn-ghost btn-sm'; sup.textContent = 'Move up';
      sup.addEventListener('click', function(){
        var prev = selected.previousElementSibling;
        if (prev && prev.classList.contains('lb-section')) { selected.parentNode.insertBefore(selected, prev); selectItem(selected, 'section'); }
      });
      toolbar.appendChild(sup);

      var sdown = document.createElement('button'); sdown.type = 'button'; sdown.className = 'btn btn-ghost btn-sm'; sdown.textContent = 'Move down';
      sdown.addEventListener('click', function(){
        var next = selected.nextElementSibling;
        if (next) { selected.parentNode.insertBefore(next, selected); selectItem(selected, 'section'); }
      });
      toolbar.appendChild(sdown);

      var sremove = document.createElement('button'); sremove.type = 'button'; sremove.className = 'btn btn-ghost btn-sm'; sremove.style.color = 'var(--bad)'; sremove.textContent = 'Remove';
      sremove.addEventListener('click', function(){ moveSection(skey, 'bodyTray'); clearSelection(); });
      toolbar.appendChild(sremove);
    }
  }

  // Selection (click/tap and keyboard).
  document.addEventListener('click', function(e){
    var block = e.target.closest('.lb-block');
    if (block) { selectItem(block, 'field'); return; }
    var section = e.target.closest('.lb-section');
    if (section) { selectItem(section, 'section'); return; }
    var addBtn = e.target.closest('[data-add-to]');
    if (addBtn) {
      var item = addBtn.closest('.lb-tray-item');
      moveField(item.dataset.block, addBtn.dataset.addTo);
      return;
    }
    var addSectionBtn = e.target.closest('[data-add-section]');
    if (addSectionBtn) {
      var sItem = addSectionBtn.closest('.lb-tray-item');
      moveSection(sItem.dataset.section, 'body');
      return;
    }
    if (! e.target.closest('.lb-shell')) { clearSelection(); }
  });
  document.addEventListener('keydown', function(e){
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('lb-block')) { e.preventDefault(); selectItem(e.target, 'field'); }
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('lb-section')) { e.preventDefault(); selectItem(e.target, 'section'); }
  });

  // Drag and drop (desktop/mouse).
  var dragKey = null, dragKind = null;
  document.addEventListener('dragstart', function(e){
    var field = e.target.closest('.lb-block, .lb-tray-item[data-block]');
    var section = e.target.closest('.lb-section, .lb-tray-item[data-section]');
    if (field) { dragKey = field.dataset.block; dragKind = 'field'; field.classList.add('dragging'); }
    else if (section) { dragKey = section.dataset.section; dragKind = 'section'; section.classList.add('dragging'); }
  });
  document.addEventListener('dragend', function(e){
    var draggable = e.target.closest('.lb-block, .lb-tray-item, .lb-section');
    if (draggable) { draggable.classList.remove('dragging'); }
    document.querySelectorAll('.lb-zone').forEach(function(z){ z.classList.remove('drag-over'); });
  });
  Object.keys(zones).forEach(function(zoneKey){
    var el = zones[zoneKey];
    var isSectionZone = zoneKey === 'body' || zoneKey === 'bodyTray';
    el.addEventListener('dragover', function(e){
      if ((isSectionZone && dragKind !== 'section') || (! isSectionZone && dragKind === 'section')) { return; }
      e.preventDefault();
      el.classList.add('drag-over');
    });
    el.addEventListener('dragleave', function(){ el.classList.remove('drag-over'); });
    el.addEventListener('drop', function(e){
      if ((isSectionZone && dragKind !== 'section') || (! isSectionZone && dragKind === 'section')) { return; }
      e.preventDefault();
      el.classList.remove('drag-over');
      if (! dragKey) return;
      var itemSelector = isSectionZone ? '.lb-section:not(.dragging)' : '.lb-block:not(.dragging)';
      var after = (zoneKey === 'tray' || zoneKey === 'bodyTray') ? null : Array.prototype.find.call(el.querySelectorAll(itemSelector), function(c){
        return e.clientY < c.getBoundingClientRect().top + c.getBoundingClientRect().height / 2;
      });
      if (isSectionZone) { moveSection(dragKey, zoneKey, after || null); } else { moveField(dragKey, zoneKey, after || null); }
      dragKey = null; dragKind = null;
    });
  });

  document.getElementById('enabledCheckbox').addEventListener('change', function(){
    document.getElementById('enabledInput').value = this.checked ? '1' : '0';
  });

  document.getElementById('layoutForm').addEventListener('submit', function(){
    var container = document.getElementById('hiddenBlockInputs');
    container.innerHTML = '';
    ['header', 'info'].forEach(function(zoneKey){
      zones[zoneKey].querySelectorAll('.lb-block').forEach(function(chip){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = zoneKey + '[]';
        input.value = chip.dataset.block;
        container.appendChild(input);
        if (zoneKey === 'header') {
          var alignInput = document.createElement('input');
          alignInput.type = 'hidden';
          alignInput.name = 'header_align[' + chip.dataset.block + ']';
          alignInput.value = chip.dataset.align || 'center';
          container.appendChild(alignInput);
        }
      });
    });
    zones.body.querySelectorAll('.lb-section').forEach(function(chip){
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'body[]';
      input.value = chip.dataset.section;
      container.appendChild(input);

      var alignInput = document.createElement('input');
      alignInput.type = 'hidden';
      alignInput.name = 'body_align[' + chip.dataset.section + ']';
      alignInput.value = chip.dataset.align || 'stretch';
      container.appendChild(alignInput);

      var columnsInput = document.createElement('input');
      columnsInput.type = 'hidden';
      columnsInput.name = 'body_columns[' + chip.dataset.section + ']';
      columnsInput.value = chip.dataset.columns || '1';
      container.appendChild(columnsInput);
    });
  });

  renderToolbar();
})();
</script>
@endsection
