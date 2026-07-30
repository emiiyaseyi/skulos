@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Results'])
@section('title', 'Results')

@push('head')
<style>
  .scores-table input[type="number"]{ max-width:5.5rem; text-align:center; padding:.45rem .3rem; }
  .scores-table th, .scores-table td{ text-align:center; }
  .scores-table td:first-child, .scores-table th:first-child{ text-align:left; }
  .scores-total{ font-weight:800; }
  .scores-table textarea{ width:14rem; min-height:3.4rem; font-size:.82rem; text-align:left; }
  .remark-suggested{ color:var(--mut); }
  .ext-request-box{ display:none; }
  .ext-request-box.show{ display:block; }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Results</h1>
    <p class="subtitle">Enter every student's score per assessment component. Report cards update as soon as you save.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.structure', ['school' => $school->slug]) }}">Assessment structure</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.grading', ['school' => $school->slug]) }}">Grading</a>
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <form method="GET" action="{{ route('school.examinations.index', ['school' => $school->slug]) }}" class="grid cols-2">
    <div class="field">
      <label for="term_id">Term</label>
      <select id="term_id" name="term_id" onchange="this.form.requestSubmit()">
        @foreach($terms as $term)
          <option value="{{ $term->id }}" @selected($termId === $term->id)>{{ $term->session?->name }}: {{ $term->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="pair">Class and subject</label>
      @if($pairs->isEmpty())
        <select id="pair" disabled><option>None assigned to you yet</option></select>
      @else
        <select id="pair" name="pair" onchange="this.form.requestSubmit()">
          <option value="">Choose a class and subject</option>
          @foreach($pairs as $pair)
            <option value="{{ $pair['class_arm_id'] }}:{{ $pair['subject_id'] }}" @selected($classArmId === $pair['class_arm_id'] && $subjectId === $pair['subject_id'])>{{ $pair['label'] }}</option>
          @endforeach
        </select>
      @endif
    </div>
  </form>
</div>

@if($narrativeClasses->isNotEmpty())
  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.4rem">Narrative Evaluation classes</h3>
    <p class="muted" style="margin-bottom:.7rem">These classes use written comments instead of scores, so they don't appear in the picker above. Enter each subject's remark from here instead.</p>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
      @foreach($narrativeClasses as $narrativeClass)
        <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class.entry', ['school' => $school->slug, 'classId' => $narrativeClass->id]) }}">{{ $narrativeClass->name }}</a>
      @endforeach
    </div>
  </div>
@endif

@if($pairs->isEmpty())
  <div class="card">
    <p class="muted">You are not assigned to teach any class or subject yet. Ask your school administrator to add a teaching assignment for you under Classes &amp; Subjects.</p>
  </div>
@elseif(! $selectedPair)
  <div class="card">
    <p class="muted">Choose a term, class, and subject above to enter scores.</p>
  </div>
@elseif($components->isEmpty())
  <div class="card">
    <p class="muted">This school has not set up any assessment components (e.g. First CA, Exam) yet. Ask your school administrator to add them before scores can be entered.</p>
  </div>
@elseif($students->isEmpty())
  <div class="card">
    <p class="muted">There are no active students in this class yet.</p>
  </div>
@else
  @if($canSave)
    <div class="card" style="margin-bottom:1.25rem">
      <h3 style="margin-bottom:.4rem">Bulk score entry</h3>
      <p class="muted" style="margin-bottom:.8rem">Download a CSV pre-filled with every student's name and any scores already entered, fill it in, then upload it back. Blank cells are left untouched, and every value is checked against the component's real maximum before saving.</p>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem">
        <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.scores.bulk-template', ['school' => $school->slug, 'term_id' => $termId, 'class_arm_id' => $classArmId, 'subject_id' => $subjectId]) }}">Download template for this subject</a>
        @if($subjectsInThisClass > 1)
          <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.scores.bulk-template', ['school' => $school->slug, 'term_id' => $termId, 'class_arm_id' => $classArmId]) }}">Download template for all my subjects in this class</a>
        @endif
      </div>
      <form method="POST" action="{{ route('school.examinations.scores.bulk-upload', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <input type="hidden" name="term_id" value="{{ $termId }}" />
        <input type="hidden" name="class_arm_id" value="{{ $classArmId }}" />
        <div class="field" style="margin-bottom:0;flex:1;min-width:14rem">
          <label for="bulk_file">Filled in CSV</label>
          <input id="bulk_file" name="file" type="file" accept=".csv,text/csv" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </form>
    </div>
  @endif

  @foreach($components as $component)
    @unless($componentOpen[$component->id] ?? true)
      <div id="ext-{{ $component->id }}" class="ext-request-box card" style="margin-bottom:1rem;background:var(--bg)">
        <form method="POST" action="{{ route('school.examinations.window-extensions.store', ['school' => $school->slug]) }}">
          @csrf
          <input type="hidden" name="assessment_component_id" value="{{ $component->id }}" />
          <input type="hidden" name="term_id" value="{{ $termId }}" />
          <p class="muted" style="margin-bottom:.5rem">Request more time to enter {{ $component->name }} scores for this class and subject.</p>
          <div class="field">
            <label for="reason-{{ $component->id }}">Reason</label>
            <textarea id="reason-{{ $component->id }}" name="reason" rows="2" required></textarea>
          </div>
          <div class="field">
            <label for="close-{{ $component->id }}">When should the window close for you</label>
            <input id="close-{{ $component->id }}" type="datetime-local" name="requested_close_at" required />
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Send request</button>
        </form>
      </div>
    @endunless
  @endforeach

  <div class="card">
    <form method="POST" id="scoresForm" action="{{ route('school.examinations.scores.save', ['school' => $school->slug]) }}">
      @csrf
      <input type="hidden" name="term_id" value="{{ $termId }}" />
      <input type="hidden" name="class_arm_id" value="{{ $classArmId }}" />
      <input type="hidden" name="subject_id" value="{{ $subjectId }}" />

      <div style="overflow-x:auto">
      <table class="scores-table table-stack">
        <thead>
          <tr>
            <th>Student</th>
            @foreach($components as $component)
              @php $open = $componentOpen[$component->id] ?? true; @endphp
              <th>
                {{ $component->name }}<div class="muted" style="font-weight:500">out of {{ rtrim(rtrim(number_format($component->max_score, 2), '0'), '.') }}</div>
                @unless($open)
                  <div style="margin-top:.3rem">
                    <span class="badge badge-bad">Closed</span>
                    <button class="btn btn-ghost btn-sm" type="button" style="font-size:.68rem;padding:.15rem .4rem" onclick="document.getElementById('ext-{{ $component->id }}').classList.toggle('show')">Request extension</button>
                  </div>
                @endunless
              </th>
            @endforeach
            <th>Total</th>
            <th>Grade</th>
            <th>Remark</th>
          </tr>
        </thead>
        <tbody>
          @foreach($students as $student)
            @php
              $existing = $existingScores->get($student->id, collect())->keyBy('assessment_component_id');
              $savedRemark = $remarks->get($student->id)?->remark;
            @endphp
            <tr>
              <td><strong>{{ $student->fullName() }}</strong><div class="muted">{{ $student->admission_number }}</div></td>
              @foreach($components as $component)
                @php $open = $componentOpen[$component->id] ?? true; @endphp
                <td data-label="{{ $component->name }}">
                  @if($canSave && $open)
                    <input type="number" step="0.01" min="0" max="{{ $component->max_score }}" class="js-score-input"
                      name="scores[{{ $student->id }}][{{ $component->id }}]"
                      value="{{ old('scores.'.$student->id.'.'.$component->id, $existing->get($component->id)?->score) }}" />
                  @elseif($canSave && ! $open)
                    <input type="number" step="0.01" min="0" max="{{ $component->max_score }}" class="js-score-input" value="{{ $existing->get($component->id)?->score }}" disabled title="Entry window closed" />
                  @else
                    {{ $existing->get($component->id)?->score ?? 'Not entered' }}
                  @endif
                </td>
              @endforeach
              <td class="scores-total js-total" data-label="Total">{{ $summaries[$student->id]['total'] ?? 0 }}</td>
              <td class="js-grade" data-label="Grade">{{ $summaries[$student->id]['grade'] ?? 'Not graded' }}</td>
              <td data-label="Remark">
                @if($canSave)
                  <textarea name="remarks[{{ $student->id }}]" placeholder="No suggestion yet" class="js-remark {{ $savedRemark ? '' : 'remark-suggested' }}" data-user-edited="{{ $savedRemark ? '1' : '0' }}">{{ old('remarks.'.$student->id, $savedRemark ?? ($suggestions[$student->id] ?? '')) }}</textarea>
                @else
                  {{ $savedRemark ?: 'Not entered' }}
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <p class="muted" style="margin-top:.6rem">Remarks are suggested from your school's comment bank based on each student's score, in grey until you save. Edit, replace, or clear any of them before saving.</p>
      @if($canSave)
        <div style="display:flex;align-items:center;gap:.8rem;margin-top:1.2rem">
          <button class="btn btn-primary" type="submit">Save scores</button>
          <span id="autosaveStatus" class="muted"></span>
        </div>
      @endif
    </form>
  </div>
@endif
@endsection

@if($canSave && $selectedPair && $students->isNotEmpty())
@push('scripts')
<script>
(function(){
  var gradingBands = @json($gradingBands ?? []);
  var commentBands = @json($commentBands ?? []);
  var form = document.getElementById('scoresForm');
  if (! form) return;

  function gradeFor(total){
    for (var i = 0; i < gradingBands.length; i++){
      var b = gradingBands[i];
      if (total >= b.min && total <= b.max) return b.grade;
    }
    return 'Not graded';
  }
  function suggestFor(total){
    for (var i = 0; i < commentBands.length; i++){
      var b = commentBands[i];
      if (total >= b.min && total <= b.max && b.texts.length){
        return b.texts[Math.floor(Math.random() * b.texts.length)];
      }
    }
    return null;
  }

  // Live total/grade calc + auto-suggested remark (user request
  // 2026-07-25), entirely client side against the same bands the server
  // itself uses, so a teacher sees the real total/grade/suggestion the
  // instant they finish typing a score, not only after Save reloads the
  // page. A remark the teacher has typed or that was already saved
  // (data-user-edited="1") is never silently overwritten.
  function recalcRow(row){
    var total = 0;
    row.querySelectorAll('.js-score-input').forEach(function(input){
      var value = parseFloat(input.value);
      if (! isNaN(value)) total += value;
    });

    var totalCell = row.querySelector('.js-total');
    var gradeCell = row.querySelector('.js-grade');
    if (totalCell) totalCell.textContent = total;
    if (gradeCell) gradeCell.textContent = total > 0 ? gradeFor(total) : 'Not graded';

    var remark = row.querySelector('.js-remark');
    if (remark && remark.dataset.userEdited !== '1' && total > 0) {
      var suggestion = suggestFor(total);
      if (suggestion) {
        remark.value = suggestion;
        remark.classList.add('remark-suggested');
      }
    }
  }

  form.querySelectorAll('tbody tr').forEach(function(row){
    row.querySelectorAll('.js-score-input').forEach(function(input){
      input.addEventListener('input', function(){ recalcRow(row); markDirty(); });
    });
    var remark = row.querySelector('.js-remark');
    if (remark) {
      remark.addEventListener('input', function(){
        remark.dataset.userEdited = '1';
        remark.classList.remove('remark-suggested');
        markDirty();
      });
    }
  });

  // Autosave every 5 seconds while there are unsaved changes (user
  // request 2026-07-25: "5s autosave"). The manual Save scores button
  // still works exactly as before (a full save + redirect); this just
  // means progress is never lost to a closed tab or a slow connection.
  var statusEl = document.getElementById('autosaveStatus');
  var dirty = false;
  var saving = false;
  function markDirty(){ dirty = true; if (statusEl) statusEl.textContent = 'Unsaved changes'; }

  setInterval(function(){
    if (! dirty || saving) return;
    saving = true;
    if (statusEl) statusEl.textContent = 'Saving…';
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(res){
        saving = false;
        if (res.ok) {
          dirty = false;
          if (statusEl) statusEl.textContent = 'Saved ' + new Date().toLocaleTimeString();
        } else if (statusEl) {
          statusEl.textContent = 'Autosave failed, use Save scores below';
        }
      })
      .catch(function(){
        saving = false;
        if (statusEl) statusEl.textContent = 'Autosave failed, use Save scores below';
      });
  }, 5000);
})();
</script>
@endpush
@endif
