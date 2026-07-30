@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Academic Insights'])
@section('title', 'Academic Insights')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Academic Insights</h1>
    <p class="subtitle">Subject strength, weak spots, and grade spread across the whole school, one term at a time.</p>
  </div>
  <form method="GET" action="{{ route('school.examinations.academic-insights', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <select name="term_id" onchange="this.form.requestSubmit()">
      @foreach($terms as $t)
        <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
      @endforeach
    </select>
    <select name="section_id" onchange="this.form.requestSubmit()">
      <option value="">Every section</option>
      @foreach($sections as $s)
        <option value="{{ $s->id }}" @selected($section && $section->id === $s->id)>{{ $s->name }}</option>
      @endforeach
    </select>
    @if($section)
      <select name="class_arm_id" onchange="this.form.requestSubmit()">
        <option value="">Every class in {{ $section->name }}</option>
        @foreach($classArms as $arm)
          <option value="{{ $arm->id }}" @selected($classArm && $classArm->id === $arm->id)>{{ $arm->label() }}</option>
        @endforeach
      </select>
    @endif
  </form>
</div>

@if(! $term)
  <div class="card"><p class="muted">No academic term has been set up yet.</p></div>
@else
  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.3rem">Subject performance</h3>
    <p class="muted" style="margin-bottom:.8rem">
      Every subject with recorded scores in {{ $term->session?->name }} {{ $term->name }}{{ $classArm ? ' for '.$classArm->label() : ($section ? ' across '.$section->name : ', whole school') }}, strongest first.
    </p>
    @if($subjectAverages->isEmpty())
      <p class="muted">No results have been recorded for this term yet.</p>
    @else
      {{-- User request 2026-07-22: "Academic Insights page should have the filter and sort option to the data there." Client side, matching the small, already-loaded-in-full subject list this page renders (a handful to a few dozen rows, not a paginated list). --}}
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:.8rem">
        <input type="search" id="ai-subject-filter" placeholder="Filter by subject" aria-label="Filter by subject" style="max-width:14rem" />
        <select id="ai-subject-sort" aria-label="Sort">
          <option value="average-desc">Sort: Average, strongest first</option>
          <option value="average-asc">Sort: Average, weakest first</option>
          <option value="subject">Sort: Subject A to Z</option>
          <option value="highest">Sort: Highest score</option>
          <option value="lowest">Sort: Lowest score</option>
          <option value="students">Sort: Students assessed</option>
        </select>
      </div>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Subject</th><th>Average</th><th>Highest</th><th>Lowest</th><th>Students assessed</th></tr></thead>
        <tbody id="ai-subject-rows">
          @foreach($subjectAverages as $row)
            <tr data-subject="{{ strtolower($row['subject']) }}" data-average="{{ $row['average'] }}" data-highest="{{ $row['highest'] }}" data-lowest="{{ $row['lowest'] }}" data-students="{{ $row['students_assessed'] }}">
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
      <script>
      (function(){
        var filterInput = document.getElementById('ai-subject-filter');
        var sortSelect = document.getElementById('ai-subject-sort');
        var tbody = document.getElementById('ai-subject-rows');
        if (! tbody) return;

        filterInput.addEventListener('input', function(){
          var q = this.value.trim().toLowerCase();
          tbody.querySelectorAll('tr').forEach(function(row){
            row.style.display = row.dataset.subject.includes(q) ? '' : 'none';
          });
        });

        sortSelect.addEventListener('change', function(){
          var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
          var value = this.value;
          var key = value === 'subject' ? 'subject' : (value === 'highest' ? 'highest' : (value === 'lowest' ? 'lowest' : (value === 'students' ? 'students' : 'average')));
          var desc = value !== 'average-asc';
          rows.sort(function(a, b){
            if (key === 'subject') { return a.dataset.subject.localeCompare(b.dataset.subject); }
            var diff = (+a.dataset[key]) - (+b.dataset[key]);
            return desc ? -diff : diff;
          });
          rows.forEach(function(row){ tbody.appendChild(row); });
        });
      })();
      </script>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.3rem">Grade distribution</h3>
    <p class="muted" style="margin-bottom:.8rem">Every subject result this term{{ $classArm ? ' for '.$classArm->label() : ($section ? ' across '.$section->name : '') }}, grouped by grade, {{ number_format($gradeTotal) }} total.</p>
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
