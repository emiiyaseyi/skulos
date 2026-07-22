@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Teacher Workload'])
@section('title', 'Teacher Workload')

@php
  $sortLink = function($column, $label) use ($sort, $dir, $sectionFilter, $school) {
    $nextDir = ($sort === $column && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = $sort === $column ? ($dir === 'desc' ? ' ↓' : ' ↑') : '';
    $params = array_filter(['school' => $school->slug, 'sort' => $column, 'dir' => $nextDir, 'section_id' => $sectionFilter]);

    return '<a href="'.route('school.staff.workload', $params).'" style="color:inherit;text-decoration:none">'.$label.$arrow.'</a>';
  };
@endphp

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Teacher Workload</h1>
    <p class="subtitle">How many subjects, classes, and students each staff member actually teaches, from real assignments, grouped by section.</p>
  </div>
  <form method="GET" action="{{ route('school.staff.workload', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:center">
    <input type="hidden" name="sort" value="{{ $sort }}" />
    <input type="hidden" name="dir" value="{{ $dir }}" />
    <select name="section_id" onchange="this.form.requestSubmit()">
      <option value="">Every section</option>
      @foreach($allSections as $section)
        <option value="{{ $section->id }}" @selected($sectionFilter === $section->id)>{{ $section->name }}</option>
      @endforeach
    </select>
  </form>
</div>

@php
  $loadCard = function($rows, $title) {
    $count = $rows->count();
    $students = $count ? $rows->first()['students'] : 0;
    $label = $count > 1 ? $count.' teachers tied' : ($rows->first()['name'] ?? 'None');
    return compact('rows', 'title', 'count', 'students', 'label');
  };
  $heaviest = $loadCard($insights['heaviest'], 'Heaviest load');
  $lightest = $loadCard($insights['lightest'], 'Lowest load');
@endphp

<div class="grid" style="grid-template-columns:repeat(5, 1fr);gap:1rem;margin-bottom:1.25rem">
  <div class="card stat" style="padding:1rem"><b>{{ $insights['teachers'] }}</b><span>Teaching staff</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ $insights['avg_subjects'] }}</b><span>Average subjects each</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ $insights['avg_students'] }}</b><span>Average students each</span></div>
  @foreach([$heaviest, $lightest] as $loadStat)
    <details class="card stat load-card" style="padding:1rem">
      <summary style="cursor:pointer;list-style:none">
        <b>{{ $loadStat['students'] }}</b>
        <span>{{ $loadStat['title'] }} ({{ $loadStat['label'] }})</span>
      </summary>
      @if($loadStat['count'] > 0)
        <ul class="load-card-list">
          @foreach($loadStat['rows'] as $row)
            <li><a href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $row['staff_id']]) }}">{{ $row['name'] }}</a> <span class="muted">{{ number_format($row['students']) }} students</span></li>
          @endforeach
        </ul>
      @endif
    </details>
  @endforeach
</div>

<style>
  .load-card summary::-webkit-details-marker{display:none}
  .load-card-list{list-style:none;margin:.6rem 0 0;padding-top:.6rem;border-top:1px solid var(--line);font-size:.82rem;display:flex;flex-direction:column;gap:.35rem}
</style>

@if($groups->isEmpty())
  <div class="card"><p class="muted">No teaching assignments or class teachers have been set up yet.</p></div>
@else
  @foreach($groups as $group)
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.6rem">{{ $group['section']->name }}</h3>
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          <th>{!! $sortLink('name', 'Staff') !!}</th>
          <th>Role</th>
          <th>{!! $sortLink('subjects', 'Subjects') !!}</th>
          <th>{!! $sortLink('classes', 'Classes') !!}</th>
          <th>{!! $sortLink('students', 'Students') !!}</th>
        </tr></thead>
        <tbody>
          @foreach($group['rows'] as $row)
            <tr>
              <td>
                <a href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $row['staff_id']]) }}">{{ $row['name'] }}</a>
                @if($row['position'])<div class="muted" style="font-size:.78rem">{{ $row['position'] }}</div>@endif
                @if($row['class_labels'])<div class="muted" style="font-size:.76rem">{{ $row['class_labels'] }}</div>@endif
              </td>
              <td>
                @if($row['is_class_teacher'])<span class="badge badge-ok">Class teacher</span>@endif
                @if($row['is_assistant_teacher'])<span class="badge">Assistant teacher</span>@endif
                @if(! $row['is_class_teacher'] && ! $row['is_assistant_teacher'])<span class="muted">Subject teacher</span>@endif
              </td>
              <td>{{ $row['subjects'] }}</td>
              <td>{{ $row['classes'] }}</td>
              <td>{{ number_format($row['students']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
  @endforeach
@endif
@endsection
