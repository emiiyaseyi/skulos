@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Student Reports'])
@section('title', 'Student Reports')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Student Reports</h1>
    <p class="subtitle">Every subject score, total, average, and class position for one class and term at a glance.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:14rem">
      <label for="class_arm_id">Class</label>
      <select id="class_arm_id" name="class_arm_id" onchange="this.form.submit()">
        <option value="">Select a class</option>
        @foreach($classArms as $arm)
          <option value="{{ $arm->id }}" @selected($selectedArm && $selectedArm->id === $arm->id)>{{ $arm->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0;min-width:14rem">
      <label for="term_id">Term</label>
      <select id="term_id" name="term_id" onchange="this.form.submit()">
        @foreach($terms as $t)
          <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->name }} ({{ $t->session?->name }})</option>
        @endforeach
      </select>
    </div>
    @if($selectedArm && $term)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.reports.students.export', ['school' => $school->slug, 'class_arm_id' => $selectedArm->id, 'term_id' => $term->id]) }}">Export CSV</a>
    @endif
  </form>
</div>

<div class="card">
  @if(! $selectedArm)
    <p class="muted">Select a class above to see its report.</p>
  @elseif($rows->isEmpty())
    <p class="muted">No scores recorded for {{ $selectedArm->label() }} in {{ $term?->name }} yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th>Position</th><th>Student</th>
        @foreach($subjects as $name)<th>{{ $name }}</th>@endforeach
        <th>Total</th><th>Average</th>
      </tr></thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $row['position'] }}</td>
            <td><strong>{{ $row['name'] }}</strong></td>
            @foreach($subjects as $subjectId => $name)
              <td>{{ number_format($row['subjects'][$subjectId] ?? 0, 1) }}</td>
            @endforeach
            <td>{{ number_format($row['total'], 1) }}</td>
            <td>{{ number_format($row['average'], 1) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
