@php($q = $question ?? null)
@php($answerLines = $q ? $q->answers->pluck('answer_text')->implode("\n") : '')
@php($correctLines = $q ? $q->answers->filter(fn($a) => $a->fraction > 0)->pluck('answer_text')->implode("\n") : '')
@php($pairLines = $q ? $q->answers->map(fn($a) => $a->answer_text.' = '.$a->feedback)->implode("\n") : '')

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
    <label for="{{ $prefix }}_points">Marks</label>
    <input id="{{ $prefix }}_points" name="points" type="number" step="0.5" min="0.5" value="{{ $q->points ?? old('points', 1) }}" required />
  </div>
</div>
<div class="field">
  <label for="{{ $prefix }}_name">Question name (optional, for your own reference)</label>
  <input id="{{ $prefix }}_name" name="name" type="text" value="{{ $q->name ?? old('name') }}" />
</div>
<div class="field">
  <label for="{{ $prefix }}_prompt">Question</label>
  <textarea id="{{ $prefix }}_prompt" name="prompt" rows="2" required>{{ $q->prompt ?? old('prompt') }}</textarea>
</div>
<div class="grid cols-2" style="gap:.75rem">
  <div class="field">
    <label for="{{ $prefix }}_category_id">Category</label>
    <select id="{{ $prefix }}_category_id" name="category_id">
      <option value="">Uncategorised</option>
      @foreach(($categories ?? collect()) as $category)
        <option value="{{ $category->id }}" @selected(($q?->category_id ?? old('category_id')) == $category->id)>{{ $category->name }}</option>
      @endforeach
    </select>
  </div>
  <div class="field">
    <label for="{{ $prefix }}_image">Image (optional)</label>
    <input id="{{ $prefix }}_image" name="image" type="file" accept=".jpg,.jpeg,.png,.webp" />
  </div>
</div>

<div id="{{ $prefix }}_wrap_mc" hidden>
  <div class="field">
    <label>Options (one per line)</label>
    <textarea name="mc_options" rows="4">{{ $q ? $answerLines : old('mc_options') }}</textarea>
  </div>
  <div class="field" id="{{ $prefix }}_wrap_mc_single">
    <label>Correct answer (must match one option exactly)</label>
    <input name="mc_correct_single" type="text" value="{{ $q && $q->type === 'multiple_choice_single' ? $correctLines : old('mc_correct_single') }}" />
  </div>
  <div class="field" id="{{ $prefix }}_wrap_mc_multiple">
    <label>Correct answers (one per line, must match options exactly)</label>
    <p class="muted" style="font-size:.78rem;margin-bottom:.3rem">Each correct option carries an equal share of the marks, so ticking all of them scores full marks. Ticking a wrong one deducts an equal share.</p>
    <textarea name="mc_correct_multiple_raw" rows="2">{{ $q && $q->type === 'multiple_choice_multiple' ? $correctLines : old('mc_correct_multiple_raw') }}</textarea>
  </div>
</div>

<div id="{{ $prefix }}_wrap_true_false" hidden>
  <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
    <input type="checkbox" name="true_false_answer" value="1" @checked($q && $q->type === 'true_false' ? $q->answers->firstWhere('answer_text', 'True')?->fraction > 0 : old('true_false_answer')) /> Correct answer is True
  </label>
</div>

<div id="{{ $prefix }}_wrap_short_answer" hidden>
  <div class="field">
    <label>Accepted answers (one per line, each is fully correct)</label>
    <textarea name="short_answer_correct" rows="3">{{ $q && $q->type === 'short_answer' ? $answerLines : old('short_answer_correct') }}</textarea>
  </div>
  <label style="display:flex;align-items:center;gap:.4rem;margin:.5rem 0">
    <input type="checkbox" name="case_sensitive" value="1" @checked($q?->case_sensitive ?? false) /> Match capitalisation exactly
  </label>
</div>

<div id="{{ $prefix }}_wrap_matching" hidden>
  <div class="field">
    <label>Pairs (one per line, "item = its correct match")</label>
    <textarea name="matching_pairs" rows="4" placeholder="Paris = France&#10;Abuja = Nigeria">{{ $q && $q->type === 'matching' ? $pairLines : old('matching_pairs') }}</textarea>
    <p class="muted" style="font-size:.78rem;margin-top:.3rem">Every right-hand value is offered as a choice against every item, so the taker cannot tell which pairing is which from the list alone.</p>
  </div>
</div>

<div id="{{ $prefix }}_wrap_essay" hidden>
  <div class="field">
    <label>Marking guidance (shown to graders only, never to the taker)</label>
    <textarea name="marking_guidance" rows="3">{{ $q->marking_guidance ?? old('marking_guidance') }}</textarea>
  </div>
</div>

<div class="field">
  <label for="{{ $prefix }}_general_feedback">General feedback (optional, shown after grading)</label>
  <textarea id="{{ $prefix }}_general_feedback" name="general_feedback" rows="2">{{ $q->general_feedback ?? old('general_feedback') }}</textarea>
</div>
<label style="display:flex;align-items:center;gap:.4rem;margin:.4rem 0">
  <input type="checkbox" name="shuffle_answers" value="1" @checked($q ? $q->shuffle_answers : true) /> Shuffle the options for each taker
</label>
<label style="display:flex;align-items:center;gap:.4rem;margin:.4rem 0">
  <input type="checkbox" name="is_mandatory" value="1" @checked($q?->is_mandatory ?? false) /> Always include this question when an assessment draws a random subset
</label>

@once
<script>
  function skulosCbtTypeChange(prefix) {
    var type = document.getElementById(prefix + '_type').value;
    ['mc', 'true_false', 'short_answer', 'matching', 'essay'].forEach(function (key) {
      var el = document.getElementById(prefix + '_wrap_' + key);
      if (el) el.hidden = true;
    });
    var mcSingle = document.getElementById(prefix + '_wrap_mc_single');
    var mcMultiple = document.getElementById(prefix + '_wrap_mc_multiple');
    if (type === 'multiple_choice_single' || type === 'multiple_choice_multiple') {
      document.getElementById(prefix + '_wrap_mc').hidden = false;
      if (mcSingle) mcSingle.hidden = (type !== 'multiple_choice_single');
      if (mcMultiple) mcMultiple.hidden = (type !== 'multiple_choice_multiple');
    } else {
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
