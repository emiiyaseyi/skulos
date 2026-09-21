@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Narrative Evaluation'])
@section('title', 'Narrative Evaluation')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Narrative Evaluation</h1>
    <p class="subtitle">Tick every class that should use a written, comment based report instead of scores, for the term below. Typically Creche/Reception and other early years classes.</p>
  </div>
</div>

{{--
  Per-class-per-term (user request 2026-07-26: "re-toggleable across
  terms"): this switcher decides which term's own toggle state the
  checkboxes below show and save, so turning it off for a later term
  never rewrites an earlier term's already-generated report cards.
--}}
<form method="GET" action="{{ route('school.examinations.narrative-evaluation', ['school' => $school->slug]) }}" style="margin-bottom:1rem">
  <div class="field" style="max-width:22rem">
    <label for="term_id">{{ \App\Support\TermLabel::current() }}</label>
    <select id="term_id" name="term_id" onchange="this.form.submit()">
      @foreach($terms as $t)
        <option value="{{ $t->id }}" @selected($term?->id === $t->id)>{{ $t->name }} &middot; {{ $t->session?->name }}@if($t->is_current) (current) @endif</option>
      @endforeach
    </select>
  </div>
</form>

@if(! $term)
  <div class="card"><p class="muted">There is no term to show yet.</p></div>
@elseif($classes->isEmpty())
  <div class="card"><p class="muted">No classes have been set up yet.</p></div>
@else
  <form method="POST" action="{{ route('school.examinations.narrative-evaluation.update', ['school' => $school->slug]) }}">
    @csrf
    <input type="hidden" name="term_id" value="{{ $term->id }}" />
    <div class="card">
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr>
            <th style="width:2.5rem"><input type="checkbox" id="selectAll" aria-label="Select every class" /></th>
            <th>Class</th>
            <th>Section</th>
            <th>Style for {{ $term->name }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($classes as $class)
            @php($isNarrative = $class->isNarrativeEvaluationForTerm($term->id))
            <tr>
              <td><input type="checkbox" class="narrative-check" name="narrative_class_ids[]" value="{{ $class->id }}" @checked($isNarrative) /></td>
              <td data-label="Class">{{ $class->name }}</td>
              <td class="muted" data-label="Section">{{ $class->section?->name }}</td>
              <td data-label="Style for {{ $term->name }}">
                @if($isNarrative)
                  <span class="badge badge-ok">Narrative Evaluation</span>
                @else
                  <span class="badge">Scored</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
    <p class="hint" style="margin-top:.6rem">A narrative class's report card always shows written comments per subject instead of a score table for {{ $term->name }}, and its Enter Scores screen is skipped for this term, scores are entered under Report Cards, class entry instead. Toggling this for a later term never changes an earlier term's already generated report cards.</p>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save for {{ $term->name }}</button>
  </form>
  <script>
    document.getElementById('selectAll')?.addEventListener('change', function () {
      document.querySelectorAll('.narrative-check').forEach(function (c) { c.checked = document.getElementById('selectAll').checked; });
    });
  </script>
@endif
@endsection
