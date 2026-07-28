@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Examination Timetable'])
@section('title', 'Examination Timetable')

@push('head')
<style>
  @media print{
    .appbar,.side,.impersonate-bar,.page-head .btn,.page-head form{display:none !important}
    .content{padding:0 !important}
    .card{box-shadow:none;border:none;break-inside:avoid;margin-bottom:.6rem !important}
  }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Examination Timetable</h1>
    <p class="subtitle">Which subject is sat, for each paper, on each exam date.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem;align-items:center">
    @if($children->count() > 1)
      <form method="GET" style="display:flex;gap:.5rem;align-items:center">
        <select name="student_id" onchange="this.form.submit()">
          <option value="all" @selected(request()->query('student_id') === 'all')>All children</option>
          @foreach($children as $child)
            <option value="{{ $child->id }}" @selected($selectedChildId === $child->id && request()->query('student_id') !== 'all')>{{ $child->fullName() }}</option>
          @endforeach
        </select>
        @if($term)<input type="hidden" name="term_id" value="{{ $term->id }}" />@endif
      </form>
    @endif
    @if($terms->isNotEmpty())
      <form method="GET" style="display:flex;gap:.5rem;align-items:center">
        <input type="hidden" name="student_id" value="{{ request()->query('student_id') === 'all' ? 'all' : $selectedChildId }}" />
        <select name="term_id" onchange="this.form.submit()" style="min-width:13rem">
          @foreach($terms as $t)
            <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
          @endforeach
        </select>
      </form>
    @endif
    @if($dates->isNotEmpty() || $allExamGrids->isNotEmpty())
      <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">Print / Save as PDF</button>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('school.timetable.mine', ['school' => $school->slug]) }}">Back to Timetable</a>
  </div>
</div>

@if($allExamGrids->isNotEmpty())
  {{-- "See all children's exam timetable at once" (user request
       2026-07-27), matching the same combined view the plain weekly
       timetable already got. --}}
  @foreach($allExamGrids as $row)
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.6rem">{{ $row['student']->fullName() }}</h3>
      @php($entries = $row['entries'])
      @php($dates = $row['dates'])
      @include('school.timetable._exam-grid-table')
    </div>
  @endforeach
@else
  <div class="card">
    @include('school.timetable._exam-grid-table')
  </div>
@endif
@endsection
