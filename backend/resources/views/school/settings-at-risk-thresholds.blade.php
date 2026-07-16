@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'At Risk Thresholds'])
@section('title', 'At Risk Thresholds')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">At risk thresholds</h1>
    <p class="subtitle">Controls when a student appears on the dashboard's Attendance at risk and Performance at risk cards.</p>
  </div>
</div>
<div class="card" style="max-width:32rem">
  <form method="POST" action="{{ route('school.settings.at-risk-thresholds.update', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="attendance_pct">Flag a student below this attendance rate</label>
      <input id="attendance_pct" name="attendance_pct" type="number" min="0" max="100" value="{{ $thresholds['attendance_pct'] }}" required /> %
    </div>
    <div class="field">
      <label for="score_pct">Flag a student below this average score</label>
      <input id="score_pct" name="score_pct" type="number" min="0" max="100" value="{{ $thresholds['score_pct'] }}" required /> %
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
