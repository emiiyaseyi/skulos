@php($q = $question ?? null)
<div class="grid cols-2" style="gap:.75rem">
  <div class="field">
    <label for="{{ $prefix }}_type">Type</label>
    <select id="{{ $prefix }}_type" name="type" onchange="skulosCbtTypeChange('{{ $prefix }}')" required>
      @foreach($types ?? \App\Models\Tenant\CbtQuestion::TYPES as $type)
        <option value="{{ $type }}" @selected(($q?->type ?? old('type')) === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
      @endforeach
    </select>
  </div>
  <div class="field">
    <label for="{{ $prefix }}_points">Points</label>
    <input id="{{ $prefix }}_points" name="points" type="number" step="0.5" min="0.5" value="{{ $q->points ?? old('points', 1) }}" required />
  </div>
</div>
<div class="field">
  <label for="{{ $prefix }}_prompt">Question</label>
  <textarea id="{{ $prefix }}_prompt" name="prompt" rows="2" required>{{ $q->prompt ?? old('prompt') }}</textarea>
</div>
<div class="grid cols-2" style="gap:.75rem">
  <div class="field">
    <label for="{{ $prefix }}_category">Category (optional)</label>
    <input id="{{ $prefix }}_category" name="category" type="text" value="{{ $q->category ?? old('category') }}" />
  </div>
  <div class="field">
    <label for="{{ $prefix }}_image">Image (optional)</label>
    <input id="{{ $prefix }}_image" name="image" type="file" accept=".jpg,.jpeg,.png,.webp" />
  </div>
</div>

<div id="{{ $prefix }}_wrap_mc" hidden>
  <div class="field">
    <label>Options (one per line)</label>
    <textarea name="mc_options" rows="4">{{ $q ? implode("\n", $q->options ?? []) : old('mc_options') }}</textarea>
  </div>
  <div class="field" id="{{ $prefix }}_wrap_mc_single">
    <label>Correct answer (must match one option exactly)</label>
    <input name="mc_correct_single" type="text" value="{{ $q && $q->type === 'multiple_choice_single' ? $q->correct_answer : old('mc_correct_single') }}" />
  </div>
  <div class="field" id="{{ $prefix }}_wrap_mc_multiple">
    <label>Correct answers (one per line, must match options exactly)</label>
    <textarea name="mc_correct_multiple_raw" rows="2">{{ $q && $q->type === 'multiple_choice_multiple' ? implode("\n", $q->correct_answer ?? []) : old('mc_correct_multiple_raw') }}</textarea>
  </div>
</div>

<div id="{{ $prefix }}_wrap_true_false" hidden>
  <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
    <input type="checkbox" name="true_false_answer" value="1" @checked(($q?->correct_answer ?? old('true_false_answer')) == true) /> Correct answer is True
  </label>
</div>

<div id="{{ $prefix }}_wrap_short_answer" hidden>
  <div class="field">
    <label>Correct answer (matched case-insensitively)</label>
    <input name="short_answer_correct" type="text" value="{{ $q && $q->type === 'short_answer' ? $q->correct_answer : old('short_answer_correct') }}" />
  </div>
</div>

<div id="{{ $prefix }}_wrap_matching" hidden>
  <div class="grid cols-2" style="gap:.75rem">
    <div class="field">
      <label>Left items (one per line)</label>
      <textarea name="matching_left" rows="3">{{ $q && $q->type === 'matching' ? implode("\n", $q->options['left'] ?? []) : old('matching_left') }}</textarea>
    </div>
    <div class="field">
      <label>Right items (one per line)</label>
      <textarea name="matching_right" rows="3">{{ $q && $q->type === 'matching' ? implode("\n", $q->options['right'] ?? []) : old('matching_right') }}</textarea>
    </div>
  </div>
  <div class="field">
    <label>Correct pairs (one per line, "left index = right index", starting at 0)</label>
    <textarea name="matching_pairs" rows="3" placeholder="0=0&#10;1=2">{{ $q && $q->type === 'matching' ? collect($q->correct_answer ?? [])->map(fn($r,$l) => "{$l}={$r}")->implode("\n") : old('matching_pairs') }}</textarea>
  </div>
</div>

@once
<script>
  function skulosCbtTypeChange(prefix) {
    var type = document.getElementById(prefix + '_type').value;
    ['mc', 'true_false', 'short_answer', 'matching'].forEach(function (key) {
      var el = document.getElementById(prefix + '_wrap_' + key);
      if (el) el.hidden = true;
    });
    var mcSingle = document.getElementById(prefix + '_wrap_mc_single');
    var mcMultiple = document.getElementById(prefix + '_wrap_mc_multiple');
    if (type === 'multiple_choice_single' || type === 'multiple_choice_multiple') {
      document.getElementById(prefix + '_wrap_mc').hidden = false;
      if (mcSingle) mcSingle.hidden = (type !== 'multiple_choice_single');
      if (mcMultiple) mcMultiple.hidden = (type !== 'multiple_choice_multiple');
    } else if (type === 'true_false' || type === 'short_answer' || type === 'matching') {
      var el = document.getElementById(prefix + '_wrap_' + type);
      if (el) el.hidden = false;
    }
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select[id$="_type"]').forEach(function (select) {
      skulosCbtTypeChange(select.id.replace('_type', ''));
    });
  });
</script>
@endonce
