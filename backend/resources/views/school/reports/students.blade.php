@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Student Reports'])
@section('title', 'Student Reports')

@php
  $sortLink = function($column, $label) use ($sort, $dir, $selectedArm, $term, $school) {
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $column ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $params = array_filter(['school' => $school->slug, 'class_arm_id' => $selectedArm?->id, 'term_id' => $term?->id, 'sort' => $column, 'dir' => $nextDir]);

    return '<a href="'.route('school.reports.students.index', $params).'" style="color:inherit;text-decoration:none">'.$label.$arrow.'</a>';
  };
@endphp

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
        @foreach($classArmsBySection as $sectionName => $arms)
          <optgroup label="{{ $sectionName }}">
            @foreach($arms as $arm)
              <option value="{{ $arm->id }}" @selected($selectedArm && $selectedArm->id === $arm->id)>{{ $arm->label() }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0;min-width:14rem">
      <label for="term_id">{{ \App\Support\TermLabel::current() }}</label>
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

@if($insights)
  <div class="grid cols-4" style="gap:1rem;margin-bottom:1.25rem">
    <div class="card stat" style="padding:1rem"><b>{{ $insights['students'] }}</b><span>Students assessed</span></div>
    <div class="card stat" style="padding:1rem"><b>{{ $insights['average'] }}%</b><span>Class average</span></div>
    <div class="card stat" style="padding:1rem"><b>{{ number_format($insights['highest']) }}</b><span>Highest total</span></div>
    <div class="card stat" style="padding:1rem"><b>{{ $insights['top']['name'] ?? 'N/A' }}</b><span>Top of the class</span></div>
  </div>
@endif

<div class="card">
  @if(! $selectedArm)
    <p class="muted">Select a class above to see its report.</p>
  @elseif($rows->isEmpty())
    <p class="muted">No scores recorded for {{ $selectedArm->label() }} in {{ $term?->name }} yet.</p>
  @else
    <div class="field" style="max-width:18rem;margin-bottom:.8rem">
      <input type="search" id="studentFilter" placeholder="Filter by student name" aria-label="Filter by student name" />
    </div>
    <div style="overflow-x:auto">
    <table id="broadsheetTable" class="table-stack">
      <thead><tr>
        <th>Position</th><th>{!! $sortLink('name', 'Student') !!}</th>
        @foreach($subjects as $name)<th>{{ $name }}</th>@endforeach
        <th>{!! $sortLink('total', 'Total') !!}</th><th>{!! $sortLink('average', 'Average') !!}</th>
      </tr></thead>
      <tbody>
        @foreach($rows as $row)
          <tr data-name="{{ strtolower($row['name']) }}">
            <td class="muted" style="font-variant-numeric:tabular-nums" data-label="Position">{{ $row['position'] }}</td>
            <td><strong>{{ $row['name'] }}</strong></td>
            @foreach($subjects as $subjectId => $name)
              <td data-label="{{ $name }}">{{ number_format($row['subjects'][$subjectId] ?? 0, 1) }}</td>
            @endforeach
            <td data-label="Total">{{ number_format($row['total'], 1) }}</td>
            <td data-label="Average">{{ number_format($row['average'], 1) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <script>
      document.getElementById('studentFilter').addEventListener('input', function(){
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('#broadsheetTable tbody tr').forEach(function(row){
          row.style.display = row.dataset.name.includes(q) ? '' : 'none';
        });
      });
    </script>
  @endif
</div>
@endsection
