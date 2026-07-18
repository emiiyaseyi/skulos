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
    <form method="POST" action="{{ route('school.examinations.scores.save', ['school' => $school->slug]) }}">
      @csrf
      <input type="hidden" name="term_id" value="{{ $termId }}" />
      <input type="hidden" name="class_arm_id" value="{{ $classArmId }}" />
      <input type="hidden" name="subject_id" value="{{ $subjectId }}" />

      <div style="overflow-x:auto">
      <table class="scores-table">
        <thead>
          <tr>
            <th>Student</th>
            @foreach($components as $component)
              @php $open = $componentOpen[$component->id] ?? true; @endphp
              <th>
                {{ $component->name }}<div class="muted" style="font-weight:500;font-size:.72rem">out of {{ rtrim(rtrim(number_format($component->max_score, 2), '0'), '.') }}</div>
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
              <td><strong>{{ $student->fullName() }}</strong><div class="muted" style="font-size:.78rem">{{ $student->admission_number }}</div></td>
              @foreach($components as $component)
                @php $open = $componentOpen[$component->id] ?? true; @endphp
                <td>
                  @if($canSave && $open)
                    <input type="number" step="0.01" min="0" max="{{ $component->max_score }}"
                      name="scores[{{ $student->id }}][{{ $component->id }}]"
                      value="{{ old('scores.'.$student->id.'.'.$component->id, $existing->get($component->id)?->score) }}" />
                  @elseif($canSave && ! $open)
                    <input type="number" step="0.01" min="0" max="{{ $component->max_score }}" value="{{ $existing->get($component->id)?->score }}" disabled title="Entry window closed" />
                  @else
                    {{ $existing->get($component->id)?->score ?? 'Not entered' }}
                  @endif
                </td>
              @endforeach
              <td class="scores-total">{{ $summaries[$student->id]['total'] ?? 0 }}</td>
              <td>{{ $summaries[$student->id]['grade'] ?? 'Not graded' }}</td>
              <td>
                @if($canSave)
                  <textarea name="remarks[{{ $student->id }}]" placeholder="No suggestion yet" class="{{ $savedRemark ? '' : 'remark-suggested' }}">{{ old('remarks.'.$student->id, $savedRemark ?? ($suggestions[$student->id] ?? '')) }}</textarea>
                @else
                  {{ $savedRemark ?: 'Not entered' }}
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <p class="muted" style="margin-top:.6rem;font-size:.82rem">Remarks are suggested from your school's comment bank based on each student's score, in grey until you save. Edit, replace, or clear any of them before saving.</p>
      @if($canSave)
        <button class="btn btn-primary" type="submit" style="margin-top:1.2rem">Save scores</button>
      @endif
    </form>
  </div>
@endif
@endsection
