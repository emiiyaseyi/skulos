{{--
  Shared "whole school, or tick specific sections" picker (user request
  2026-09-09, per-section branding). Expects $field (e.g. 'logo' or
  'brand_color', matching the *_scope/*_sections[] names the receiving
  controller validates) and $sections. Used on both the Branding and
  School Profile screens, so a school can set a per-section logo from
  either place rather than only one.
--}}
<div class="scope-choice">
  <label><input type="radio" name="{{ $field }}_scope" value="school" checked data-scope="{{ $field }}"> Whole school</label>
  <label><input type="radio" name="{{ $field }}_scope" value="sections" data-scope="{{ $field }}"> Just some sections</label>
</div>
<div class="section-ticks" data-scope-panel="{{ $field }}">
  @foreach($sections as $s)
    <label><input type="checkbox" name="{{ $field }}_sections[]" value="{{ $s->id }}"> {{ $s->name }}</label>
  @endforeach
</div>
