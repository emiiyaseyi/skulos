@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Academic Insights'])
@section('title', 'Academic Insights')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Academic Insights</h1>
    <p class="subtitle">Subject strength, weak spots, and grade spread across the whole school, one term at a time.</p>
  </div>
  <form method="GET" action="{{ route('school.examinations.academic-insights', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:center">
    <select name="term_id" onchange="this.form.requestSubmit()">
      @foreach($terms as $t)
        <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
      @endforeach
    </select>
  </form>
</div>

@if(! $term)
  <div class="card"><p class="muted">No academic term has been set up yet.</p></div>
@else
  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.3rem">Subject performance</h3>
    <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">Every subject with recorded scores in {{ $term->session?->name }} {{ $term->name }}, strongest first.</p>
    @if($subjectAverages->isEmpty())
      <p class="muted">No results have been recorded for this term yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Subject</th><th>Average</th><th>Highest</th><th>Lowest</th><th>Students assessed</th></tr></thead>
        <tbody>
          @foreach($subjectAverages as $row)
            <tr>
              <td>{{ $row['subject'] }}</td>
              <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                  <div style="background:var(--soft);border-radius:999px;height:.5rem;width:6rem;overflow:hidden">
                    <div style="background:var(--accent);height:100%;width:{{ $row['average'] }}%"></div>
                  </div>
                  <span>{{ $row['average'] }}%</span>
                </div>
              </td>
              <td>{{ $row['highest'] }}%</td>
              <td>{{ $row['lowest'] }}%</td>
              <td>{{ $row['students_assessed'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.3rem">Grade distribution</h3>
    <p class="muted" style="font-size:.8rem;margin-bottom:.8rem">Every subject result this term, grouped by grade, {{ number_format($gradeTotal) }} total.</p>
    @if(empty($gradeDistribution))
      <p class="muted">No results have been recorded for this term yet.</p>
    @else
      <div style="display:flex;flex-direction:column;gap:.6rem">
        @foreach($gradeDistribution as $grade => $count)
          <div>
            <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem">
              <strong>{{ $grade }}</strong><span class="muted">{{ $count }} ({{ $gradeTotal ? round($count / $gradeTotal * 100) : 0 }}%)</span>
            </div>
            <div style="background:var(--soft);border-radius:999px;height:.5rem;overflow:hidden">
              <div style="background:var(--accent);height:100%;width:{{ $gradeTotal ? round($count / $gradeTotal * 100) : 0 }}%"></div>
            </div>
          </div>
        @endforeach
      </div>
    @endif
  </div>
@endif
@endsection
