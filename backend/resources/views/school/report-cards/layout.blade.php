@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Layout Designer'])
@section('title', 'Report Card Layout Designer')

@push('head')
<style>
  .lb-zones{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;align-items:start}
  @media(max-width:760px){.lb-zones{grid-template-columns:1fr}}
  .lb-zone{border:2px dashed var(--line);border-radius:12px;padding:.7rem;min-height:120px;background:var(--bg)}
  .lb-zone.drag-over{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 8%,var(--bg))}
  .lb-zone-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);margin-bottom:.6rem}
  .lb-chip{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:.5rem .6rem;margin-bottom:.5rem;cursor:grab}
  .lb-chip.dragging{opacity:.4}
  .lb-chip:last-child{margin-bottom:0}
  .lb-label{font-weight:700;font-size:.85rem;display:block;margin-bottom:.4rem}
  .lb-controls{display:flex;gap:.35rem;align-items:center;flex-wrap:wrap}
  .lb-controls select{min-height:2.75rem;font-size:.78rem;padding:.3rem .4rem}
  .lb-empty{color:var(--mut-dim);font-size:.78rem;font-style:italic}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Layout Designer</h1>
    <p class="subtitle">Drag a block into Header or Info Strip, or use the arrows and dropdown on a phone. Anything left in Unused does not print.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.edit', ['school' => $school->slug]) }}">Back to Report settings</a>
</div>

<form method="POST" action="{{ route('school.report-cards.layout.update', ['school' => $school->slug]) }}" id="layoutForm">
  @csrf
  <input type="hidden" name="enabled" id="enabledInput" value="{{ $enabled ? '1' : '0' }}" />
  <div id="hiddenBlockInputs"></div>

  <div class="card" style="margin-bottom:1.1rem">
    <label style="display:flex;align-items:center;gap:.6rem;margin:0">
      <input type="checkbox" id="enabledCheckbox" @checked($enabled) />
      <span>Use this custom layout instead of the active template's fixed header</span>
    </label>
    <p class="muted" style="font-size:.8rem;margin-top:.4rem">Turned off, every template keeps its own built in header exactly as designed. Turned on, the arrangement below replaces it on every template, the subject table, remarks, traits, and stamp/signature below are unaffected.</p>
  </div>

  <div class="card">
    <div class="lb-zones">
      <div>
        <div class="lb-zone-title">Header</div>
        <div class="lb-zone" data-zone="header" id="zone-header"></div>
      </div>
      <div>
        <div class="lb-zone-title">Info Strip</div>
        <div class="lb-zone" data-zone="info" id="zone-info"></div>
      </div>
      <div>
        <div class="lb-zone-title">Unused</div>
        <div class="lb-zone" data-zone="unused" id="zone-unused"></div>
      </div>
    </div>
  </div>

  <button class="btn btn-primary" type="submit" style="margin-top:1.1rem">Save layout</button>
</form>

<template id="chipTemplate">
  <div class="lb-chip" draggable="true">
    <span class="lb-label"></span>
    <div class="lb-controls">
      <button type="button" class="btn btn-ghost btn-sm" data-dir="up">Up</button>
      <button type="button" class="btn btn-ghost btn-sm" data-dir="down">Down</button>
      <select aria-label="Move to zone">
        <option value="header">Header</option>
        <option value="info">Info Strip</option>
        <option value="unused">Unused</option>
      </select>
    </div>
  </div>
</template>

<script>
(function(){
  var blocks = @json($blocks);
  var zones = @json($zones);
  var placed = {};
  Object.keys(zones).forEach(function(zoneKey){
    (zones[zoneKey] || []).forEach(function(blockKey){ placed[blockKey] = zoneKey; });
  });

  var template = document.getElementById('chipTemplate');
  var zoneEls = {
    header: document.getElementById('zone-header'),
    info: document.getElementById('zone-info'),
    unused: document.getElementById('zone-unused'),
  };

  function makeChip(key, label){
    var node = template.content.firstElementChild.cloneNode(true);
    node.dataset.block = key;
    node.querySelector('.lb-label').textContent = label;
    node.querySelector('select').value = placed[key] || 'unused';
    return node;
  }

  // Render in each zone's saved order first, then any never-placed block
  // (a newly added catalog entry) lands in Unused rather than vanishing.
  ['header', 'info'].forEach(function(zoneKey){
    (zones[zoneKey] || []).forEach(function(key){
      if (blocks[key]) { zoneEls[zoneKey].appendChild(makeChip(key, blocks[key])); }
    });
  });
  Object.keys(blocks).forEach(function(key){
    if (! placed[key]) { zoneEls.unused.appendChild(makeChip(key, blocks[key])); }
  });

  function moveChip(chip, targetZone, before){
    var target = zoneEls[targetZone];
    if (before) { target.insertBefore(chip, before); } else { target.appendChild(chip); }
    chip.querySelector('select').value = targetZone;
  }

  // Drag and drop (mouse/desktop).
  var dragged = null;
  document.querySelectorAll('.lb-chip').forEach(function(chip){
    chip.addEventListener('dragstart', function(){ dragged = chip; chip.classList.add('dragging'); });
    chip.addEventListener('dragend', function(){ chip.classList.remove('dragging'); });
  });
  Object.keys(zoneEls).forEach(function(zoneKey){
    var el = zoneEls[zoneKey];
    el.addEventListener('dragover', function(e){
      e.preventDefault();
      el.classList.add('drag-over');
      var after = Array.prototype.find.call(el.querySelectorAll('.lb-chip:not(.dragging)'), function(c){
        return e.clientY < c.getBoundingClientRect().top + c.getBoundingClientRect().height / 2;
      });
      if (dragged) { moveChip(dragged, zoneKey, after || null); }
    });
    el.addEventListener('dragleave', function(){ el.classList.remove('drag-over'); });
    el.addEventListener('drop', function(e){ e.preventDefault(); el.classList.remove('drag-over'); });
  });

  // Arrow buttons and the zone dropdown (works without drag, phone friendly).
  document.querySelectorAll('.lb-chip').forEach(function(chip){
    chip.querySelector('[data-dir="up"]').addEventListener('click', function(){
      var prev = chip.previousElementSibling;
      if (prev) { chip.parentNode.insertBefore(chip, prev); }
    });
    chip.querySelector('[data-dir="down"]').addEventListener('click', function(){
      var next = chip.nextElementSibling;
      if (next) { chip.parentNode.insertBefore(next, chip); }
    });
    chip.querySelector('select').addEventListener('change', function(){
      moveChip(chip, this.value, null);
    });
  });

  document.getElementById('enabledCheckbox').addEventListener('change', function(){
    document.getElementById('enabledInput').value = this.checked ? '1' : '0';
  });

  document.getElementById('layoutForm').addEventListener('submit', function(){
    var container = document.getElementById('hiddenBlockInputs');
    container.innerHTML = '';
    ['header', 'info'].forEach(function(zoneKey){
      zoneEls[zoneKey].querySelectorAll('.lb-chip').forEach(function(chip){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = zoneKey + '[]';
        input.value = chip.dataset.block;
        container.appendChild(input);
      });
    });
  });
})();
</script>
@endsection
