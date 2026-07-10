@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Results'])
@section('title', 'Results')

@push('head')
<style>
  .scores-table input[type="number"]{ max-width:5.5rem; text-align:center; padding:.45rem .3rem; }
  .scores-table th, .scores-table td{ text-align:center; }
  .scores-table td:first-child, .scores-table th:first-child{ text-align:left; }
  .scores-total{ font-weight:800; }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Results</h1>
    <p class="subtitle">Enter every student's score per assessment component. Report cards update as soon as you save.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.structure', ['school' => $school->slug]) }}">Assessment structure</a>
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
              <th>{{ $component->name }}<div class="muted" style="font-weight:500;font-size:.72rem">out of {{ rtrim(rtrim(number_format($component->max_score, 2), '0'), '.') }}</div></th>
            @endforeach
            <th>Total</th>
            <th>Grade</th>
          </tr>
        </thead>
        <tbody>
          @foreach($students as $student)
            @php $existing = $existingScores->get($student->id, collect())->keyBy('assessment_component_id'); @endphp
            <tr>
              <td><strong>{{ $student->fullName() }}</strong><div class="muted" style="font-size:.78rem">{{ $student->admission_number }}</div></td>
              @foreach($components as $component)
                <td>
                  @if($canSave)
                    <input type="number" step="0.01" min="0" max="{{ $component->max_score }}"
                      name="scores[{{ $student->id }}][{{ $component->id }}]"
                      value="{{ old('scores.'.$student->id.'.'.$component->id, $existing->get($component->id)?->score) }}" />
                  @else
                    {{ $existing->get($component->id)?->score ?? 'Not entered' }}
                  @endif
                </td>
              @endforeach
              <td class="scores-total">{{ $summaries[$student->id]['total'] ?? 0 }}</td>
              <td>{{ $summaries[$student->id]['grade'] ?? 'Not graded' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      @if($canSave)
        <button class="btn btn-primary" type="submit" style="margin-top:1.2rem">Save scores</button>
      @endif
    </form>
  </div>
@endif
@endsection
