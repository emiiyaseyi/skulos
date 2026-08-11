@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Promote Students'])
@section('title', 'Promote Students')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Promote Students</h1>
    <p class="subtitle">Move every eligible student up to the next class based on their average for a chosen term.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.promotions.index', ['school' => $school->slug]) }}">Past runs</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:14rem">
      <label for="term_id">Term to evaluate</label>
      <select id="term_id" name="term_id" onchange="this.form.submit()">
        @foreach($terms as $t)
          <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0;min-width:9rem">
      <label for="threshold">Average mark to promote (%)</label>
      <input id="threshold" name="threshold" type="number" min="0" max="100" step="0.5" value="{{ $threshold }}" onchange="this.form.submit()" />
    </div>
  </form>
</div>

@if($existingRun)
  <div class="flash" style="margin-bottom:1.1rem">
    A promotion run already exists for this term ({{ $existingRun->created_at->diffForHumans() }}, {{ $existingRun->promoted_count }} promoted).
    Running again is safe — anyone already moved won't be moved twice — but check
    <a href="{{ route('school.promotions.show', ['school' => $school->slug, 'runId' => $existingRun->id]) }}">that earlier report</a> first if you're not sure why you're running this again.
  </div>
@endif

<div class="card">
  @if(! $term)
    <p class="muted">No academic term has been set up yet.</p>
  @elseif($preview->isEmpty())
    <p class="muted">No class has any active students yet.</p>
  @else
    <p class="muted" style="margin-bottom:.8rem">Preview at the current {{ $threshold }}% threshold, before anything actually moves.</p>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Class</th><th>Students</th><th>Would promote</th><th>Would repeat</th><th>No results</th></tr></thead>
      <tbody>
        @foreach($preview as $row)
          <tr>
            <td data-label="Class"><strong>{{ $row['label'] }}</strong></td>
            <td data-label="Students">{{ $row['student_count'] }}</td>
            <td data-label="Would promote" class="muted">{{ $row['would_promote'] }}</td>
            <td data-label="Would repeat" class="muted">{{ $row['would_repeat'] }}</td>
            <td data-label="No results" class="muted">{{ $row['no_results'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <form method="POST" action="{{ route('school.promotions.store', ['school' => $school->slug]) }}" style="margin-top:1rem"
      onsubmit="return confirm('Promote every eligible student across all classes based on {{ $term->session?->name }} {{ $term->name }} at a {{ $threshold }}% threshold? This moves real students to their next class right away.')">
      @csrf
      <input type="hidden" name="term_id" value="{{ $term->id }}" />
      <input type="hidden" name="threshold" value="{{ $threshold }}" />
      <div class="field" style="max-width:20rem">
        <label for="to_term_id">Label this run as promoting into (optional)</label>
        <select id="to_term_id" name="to_term_id">
          <option value="">Not specified</option>
          @foreach($terms as $t)
            <option value="{{ $t->id }}">{{ $t->session?->name }} {{ $t->name }}</option>
          @endforeach
        </select>
        <div class="hint">A label only, for the report — this does not change the school's current term.</div>
      </div>
      <button class="btn btn-primary" type="submit">Run promotion</button>
    </form>
  @endif
</div>
@endsection
