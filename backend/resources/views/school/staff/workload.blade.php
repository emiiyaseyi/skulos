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

{{--
  Two grids, not one row of 5 (found live 2026-07-25: a hardcoded
  grid-template-columns:repeat(5,1fr) never collapses on a phone, unlike
  every other card row in the app, which uses the shared .cols-N classes
  that already do, and 5 has no column count that divides it evenly per
  the standing grid rule). The first 3 plain counts and the 2 clickable
  load cards are already a natural, distinct grouping, so splitting them
  reuses .cols-3/.cols-2 as they are rather than inventing a 5-up class.
--}}
<div class="grid cols-3" style="margin-bottom:1rem">
  <div class="card stat" style="padding:1rem"><b>{{ $insights['teachers'] }}</b><span>Teaching staff</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ $insights['avg_subjects'] }}</b><span>Average subjects each</span></div>
  <div class="card stat" style="padding:1rem"><b>{{ $insights['avg_students'] }}</b><span>Average students each</span></div>
</div>
<div class="grid cols-2" style="margin-bottom:1.25rem">
  @foreach([$heaviest, $lightest] as $i => $loadStat)
    <button type="button" class="card stat load-card" style="padding:1rem;text-align:left;{{ $loadStat['count'] === 0 ? 'cursor:default' : 'cursor:pointer' }}" data-open-popup="loadPopup{{ $i }}" @disabled($loadStat['count'] === 0)>
      <b>{{ $loadStat['students'] }}</b>
      <span>{{ $loadStat['title'] }} ({{ $loadStat['label'] }})</span>
    </button>
  @endforeach
</div>

{{-- A real popup, not an inline dropdown that just pushes the rest of
     the page down (user report 2026-07-22: "this should be as pop up
     and not dropdown list on the card"), the same scrim pattern every
     other popup in this app already uses. --}}
@foreach([$heaviest, $lightest] as $i => $loadStat)
  @if($loadStat['count'] > 0)
    <div class="load-scrim" id="loadPopup{{ $i }}" role="dialog" aria-modal="true" aria-labelledby="loadPopupTitle{{ $i }}">
      <div class="load-popup-card">
        <div class="row-between" style="margin-bottom:.8rem">
          <h3 id="loadPopupTitle{{ $i }}" style="margin:0">{{ $loadStat['title'] }}</h3>
          <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('loadPopup{{ $i }}').classList.remove('show')">Close</button>
        </div>
        <ul class="load-popup-list">
          @foreach($loadStat['rows'] as $row)
            <li><a href="{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $row['staff_id']]) }}">{{ $row['name'] }}</a> <span class="muted">{{ number_format($row['students']) }} students</span></li>
          @endforeach
        </ul>
      </div>
    </div>
  @endif
@endforeach

<style>
  .load-scrim{display:none;position:fixed;inset:0;background:rgba(8,12,20,.6);z-index:210;place-items:center;padding:1.25rem}
  .load-scrim.show{display:grid}
  .load-popup-card{width:100%;max-width:26rem;max-height:80vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.4rem;box-shadow:var(--shadow)}
  .load-popup-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem;font-size:.88rem}
</style>
<script>
  (function(){
    document.querySelectorAll('[data-open-popup]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById(btn.dataset.openPopup)?.classList.add('show');
      });
    });
    document.querySelectorAll('.load-scrim').forEach(function(scrim){
      scrim.addEventListener('click', function(e){ if (e.target === scrim) scrim.classList.remove('show'); });
    });
    document.addEventListener('keydown', function(e){
      if (e.key !== 'Escape') return;
      document.querySelectorAll('.load-scrim.show').forEach(function(scrim){ scrim.classList.remove('show'); });
    });
  })();
</script>

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
