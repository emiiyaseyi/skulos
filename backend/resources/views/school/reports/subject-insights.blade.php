@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Subject Insights'])
@section('title', 'Subject Insights')

@php
  $sortLink = function($column, $label) use ($sort, $dir, $classArmId, $subjectId, $term, $school, $fromDate, $toDate) {
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $column ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $params = array_filter([
      'school' => $school->slug, 'class_arm_id' => $classArmId, 'subject_id' => $subjectId, 'term_id' => $term?->id,
      'from_date' => $fromDate?->toDateString(), 'to_date' => $toDate?->toDateString(),
      'sort' => $column, 'dir' => $nextDir,
    ]);

    return '<a href="'.route('school.reports.subject-insights.index', $params).'" style="color:inherit;text-decoration:none">'.$label.$arrow.'</a>';
  };
@endphp

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Subject Insights</h1>
    <p class="subtitle">Average, highest, and lowest scores for every class and subject you teach, one term at a time.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:14rem">
      <label for="term_id">Term</label>
      <select id="term_id" name="term_id" onchange="this.form.submit()">
        @foreach($terms as $t)
          <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
        @endforeach
      </select>
    </div>
    @if($classOptions->isNotEmpty())
      <div class="field" style="margin:0;min-width:14rem">
        <label for="class_arm_id">Class</label>
        <select id="class_arm_id" name="class_arm_id" onchange="this.form.submit()">
          <option value="">Every class you teach</option>
          @foreach($classOptions as $id => $label)
            <option value="{{ $id }}" @selected($classArmId === (int) $id)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    @endif
    @if($subjectOptions->isNotEmpty())
      <div class="field" style="margin:0;min-width:14rem">
        <label for="subject_id">Subject</label>
        <select id="subject_id" name="subject_id" onchange="this.form.submit()">
          <option value="">Every subject you teach</option>
          @foreach($subjectOptions as $id => $name)
            <option value="{{ $id }}" @selected($subjectId === (int) $id)>{{ $name }}</option>
          @endforeach
        </select>
      </div>
    @endif
    <div class="field" style="margin:0">
      <label for="from_date">Scores entered from</label>
      <input type="date" id="from_date" name="from_date" value="{{ $fromDate?->toDateString() }}" />
    </div>
    <div class="field" style="margin:0">
      <label for="to_date">To</label>
      <input type="date" id="to_date" name="to_date" value="{{ $toDate?->toDateString() }}" />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
    @if($fromDate || $toDate)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.reports.subject-insights.index', array_filter(['school' => $school->slug, 'class_arm_id' => $classArmId, 'subject_id' => $subjectId, 'term_id' => $term?->id])) }}">Clear dates</a>
    @endif
  </form>
</div>

<div class="card">
  @if(! $hasAnyTeachingAssignment)
    <p class="muted">No class or subject is currently assigned to you for teaching.</p>
  @elseif(! $term)
    <p class="muted">No academic term has been set up yet.</p>
  @elseif($rows->isEmpty())
    <p class="muted">No scores have been recorded for {{ $term->session?->name }} {{ $term->name }} yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th>{!! $sortLink('class_label', 'Class') !!}</th>
        <th>{!! $sortLink('subject_name', 'Subject') !!}</th>
        <th>{!! $sortLink('students', 'Students assessed') !!}</th>
        <th>{!! $sortLink('average', 'Average') !!}</th>
        <th>{!! $sortLink('highest', 'Highest') !!}</th>
        <th>{!! $sortLink('lowest', 'Lowest') !!}</th>
      </tr></thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            <td>{{ $row['class_label'] }}</td>
            <td>{{ $row['subject_name'] }}</td>
            <td>{{ $row['students'] }}</td>
            <td>
              <div style="display:flex;align-items:center;gap:.5rem">
                <div style="background:var(--soft);border-radius:999px;height:.5rem;width:6rem;overflow:hidden">
                  <div style="background:var(--accent);height:100%;width:{{ min(100, $row['average']) }}%"></div>
                </div>
                <span>{{ number_format($row['average'], 1) }}</span>
              </div>
            </td>
            <td>{{ number_format($row['highest'], 1) }}</td>
            <td>{{ number_format($row['lowest'], 1) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
