{{--
  Lightweight, dependency-free rich-text editor: a contenteditable surface
  with a small toolbar (bold, italic, underline, highlight, lists, align).
  No external library or CDN, in keeping with the project's mobile-first,
  lightweight, Blade-only ethos. The editor's HTML is copied into a hidden
  field on submit; the SERVER re-sanitizes it with App\Support\HtmlSanitizer
  regardless, so the client toolbar is only a convenience, never the
  security boundary.

  Params: $name (hidden field name), $value (optional initial HTML).
--}}
@php $rtId = 'rt_'.\Illuminate\Support\Str::random(6); @endphp
<div class="rt-wrap" data-rt="{{ $rtId }}">
  <div class="rt-toolbar">
    <button type="button" class="rt-btn" data-cmd="bold" title="Bold"><b>B</b></button>
    <button type="button" class="rt-btn" data-cmd="italic" title="Italic"><i>I</i></button>
    <button type="button" class="rt-btn" data-cmd="underline" title="Underline"><u>U</u></button>
    <button type="button" class="rt-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
    <button type="button" class="rt-btn" data-cmd="hiliteColor" data-value="#fff59d" title="Highlight"><span style="background:#fff59d;padding:0 .2rem">H</span></button>
    <button type="button" class="rt-btn" data-cmd="insertUnorderedList" title="Bulleted list">&bull;&nbsp;List</button>
    <button type="button" class="rt-btn" data-cmd="insertOrderedList" title="Numbered list">1.&nbsp;List</button>
    <button type="button" class="rt-btn" data-cmd="justifyLeft" title="Align left">&#8676;</button>
    <button type="button" class="rt-btn" data-cmd="justifyCenter" title="Align centre">&#8596;</button>
    <button type="button" class="rt-btn" data-cmd="justifyRight" title="Align right">&#8677;</button>
    <button type="button" class="rt-btn" data-cmd="removeFormat" title="Clear formatting">&#10005;</button>
  </div>
  <div class="rt-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message body">{!! $value ?? '' !!}</div>
  <input type="hidden" name="{{ $name }}" class="rt-input" value="{{ $value ?? '' }}" />
</div>

@once
@push('head')
<style>
  .rt-wrap{border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--bg)}
  .rt-toolbar{display:flex;flex-wrap:wrap;gap:.15rem;padding:.35rem;border-bottom:1px solid var(--line);background:var(--panel)}
  .rt-btn{border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:6px;min-width:2rem;height:2rem;padding:0 .5rem;cursor:pointer;font-size:.85rem;line-height:1}
  .rt-btn:hover{background:var(--soft)}
  .rt-editor{min-height:9rem;padding:.75rem .85rem;font-size:.95rem;line-height:1.55;outline:none}
  .rt-editor:focus{box-shadow:inset 0 0 0 2px color-mix(in srgb,var(--accent) 45%,transparent)}
  .rt-editor ul,.rt-editor ol{margin:.4rem 0 .4rem 1.4rem}
  .rt-editor:empty::before{content:attr(data-placeholder);color:var(--mut-dim)}
</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('.rt-wrap').forEach(function(wrap){
  var editor = wrap.querySelector('.rt-editor');
  var input = wrap.querySelector('.rt-input');
  wrap.querySelectorAll('.rt-btn').forEach(function(btn){
    btn.addEventListener('mousedown', function(e){ e.preventDefault(); }); // keep selection
    btn.addEventListener('click', function(){
      editor.focus();
      var cmd = btn.getAttribute('data-cmd');
      var val = btn.getAttribute('data-value') || null;
      try { document.execCommand(cmd, false, val); } catch(e){}
      input.value = editor.innerHTML;
    });
  });
  function sync(){ input.value = editor.innerHTML; }
  editor.addEventListener('input', sync);
  editor.addEventListener('blur', sync);
  var form = wrap.closest('form');
  if (form) form.addEventListener('submit', sync);
});
</script>
@endpush
@endonce
