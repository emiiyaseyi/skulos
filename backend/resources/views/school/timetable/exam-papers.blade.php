@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Exam Day Structure'])
@section('title', 'Exam Day Structure')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Exam Day Structure</h1>
    <p class="subtitle">Set the papers and breaks for an exam day once, in order. Give the first paper a start time and every other time updates itself.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.exam.index', ['school' => $school->slug]) }}">Back to Examination Timetable</a>
</div>

<div class="card">
  <form method="POST" action="{{ route('school.timetable.exam.papers.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="field" style="max-width:12rem">
      <label for="first_start">First paper starts</label>
      <input id="first_start" type="time" name="first_start" value="{{ old('first_start', $papers->first()?->starts_at ? substr($papers->first()->starts_at, 0, 5) : '08:00') }}" required />
    </div>

    {{-- Explicit numeric indices throughout (papers[0][name],
         papers[0][is_break], ...), not papers[][name]: an unchecked
         checkbox is simply omitted from the submit, and PHP's [] auto-
         index assigns purely by encounter order, so rows with a mix of
         checked/unchecked "Break" boxes would silently misalign every
         field after the first checkbox once submitted. A hidden 0 right
         before each checkbox, same explicit index, same name, is the
         standard fix: both submit when checked (last one wins, "1"),
         only the hidden 0 submits when unchecked. --}}
    <div id="paperRows">
      @forelse($papers as $index => $paper)
        <div class="row-between" style="gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;align-items:flex-end">
          <input name="papers[{{ $index }}][name]" value="{{ $paper->name }}" placeholder="e.g. Paper 1" required style="flex:1;min-width:8rem" />
          <div class="field" style="margin:0;width:9rem">
            <label style="font-size:.72rem">Duration (minutes)</label>
            <input type="number" name="papers[{{ $index }}][duration_minutes]" value="{{ $paper->duration_minutes }}" min="1" max="600" required />
          </div>
          <label style="display:flex;align-items:center;gap:.4rem;font-weight:400">
            <input type="hidden" name="papers[{{ $index }}][is_break]" value="0" />
            <input type="checkbox" name="papers[{{ $index }}][is_break]" value="1" @checked($paper->is_break) />
            <span>Break (not assignable to a subject)</span>
          </label>
          <span class="muted" style="font-size:.8rem">{{ $paper->timeLabel() }}</span>
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.row-between').remove()">Remove</button>
        </div>
      @empty
        <div class="row-between" style="gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;align-items:flex-end">
          <input name="papers[0][name]" value="Paper 1" required style="flex:1;min-width:8rem" />
          <div class="field" style="margin:0;width:9rem">
            <label style="font-size:.72rem">Duration (minutes)</label>
            <input type="number" name="papers[0][duration_minutes]" value="60" min="1" max="600" required />
          </div>
          <label style="display:flex;align-items:center;gap:.4rem;font-weight:400">
            <input type="hidden" name="papers[0][is_break]" value="0" />
            <input type="checkbox" name="papers[0][is_break]" value="1" />
            <span>Break (not assignable to a subject)</span>
          </label>
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.row-between').remove()">Remove</button>
        </div>
      @endforelse
    </div>

    <button type="button" class="btn btn-ghost btn-sm" id="addPaperRow" style="margin-bottom:1rem">Add another row</button>
    <div>
      <button class="btn btn-primary btn-sm" type="submit">Save exam day structure</button>
    </div>
  </form>
</div>

<script>
  var nextPaperIndex = {{ max($papers->count(), 1) }};
  document.getElementById('addPaperRow').addEventListener('click', function(){
    var i = nextPaperIndex++;
    var row = document.createElement('div');
    row.className = 'row-between';
    row.style.cssText = 'gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;align-items:flex-end';
    row.innerHTML = '<input name="papers['+i+'][name]" placeholder="e.g. Break" required style="flex:1;min-width:8rem" />'
      + '<div class="field" style="margin:0;width:9rem"><label style="font-size:.72rem">Duration (minutes)</label><input type="number" name="papers['+i+'][duration_minutes]" value="30" min="1" max="600" required /></div>'
      + '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400"><input type="hidden" name="papers['+i+'][is_break]" value="0" /><input type="checkbox" name="papers['+i+'][is_break]" value="1" /> <span>Break (not assignable to a subject)</span></label>'
      + '<button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'.row-between\').remove()">Remove</button>';
    document.getElementById('paperRows').appendChild(row);
  });
</script>
@endsection
