@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Narrative Evaluation'])
@section('title', 'Narrative Evaluation')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Narrative Evaluation</h1>
    <p class="subtitle">Tick every class that should use a written, comment based report instead of scores. Typically Creche/Reception and other early years classes.</p>
  </div>
</div>

@if($classes->isEmpty())
  <div class="card"><p class="muted">No classes have been set up yet.</p></div>
@else
  <form method="POST" action="{{ route('school.examinations.narrative-evaluation.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="card">
      <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th style="width:2.5rem"><input type="checkbox" id="selectAll" aria-label="Select every class" /></th>
            <th>Class</th>
            <th>Section</th>
            <th>Current style</th>
          </tr>
        </thead>
        <tbody>
          @foreach($classes as $class)
            <tr>
              <td><input type="checkbox" class="narrative-check" name="narrative_class_ids[]" value="{{ $class->id }}" @checked($class->isNarrativeEvaluation()) /></td>
              <td>{{ $class->name }}</td>
              <td class="muted">{{ $class->section?->name }}</td>
              <td>
                @if($class->isNarrativeEvaluation())
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
    <p class="hint" style="margin-top:.6rem">A narrative class's report card always shows written comments per subject instead of a score table, and its Enter Scores screen is skipped, scores are entered under Report Cards, class entry instead.</p>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save</button>
  </form>
  <script>
    document.getElementById('selectAll')?.addEventListener('change', function () {
      document.querySelectorAll('.narrative-check').forEach(function (c) { c.checked = document.getElementById('selectAll').checked; });
    });
  </script>
@endif
@endsection
