@extends('layouts.app', ['portal' => $portal ?? 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $session->assessment->title])
@section('title', $session->assessment->title)
{{-- The admissions portal has no nav-admissions partial at all — every
     applicant-facing view goes bare (see admissions/dashboard.blade.php
     and its siblings), unlike the school portal's own chrome. --}}
@if(($portal ?? 'school') === 'admissions')
  @section('bare', true)
@endif

@php
  $isAdmissions = ($portal ?? 'school') === 'admissions';
  $applicantName = $isAdmissions ? ($session->takeable?->name) : null;
  $admLogo = $isAdmissions ? ($school->branding()['logo'] ?? null) : null;
@endphp

@if($isAdmissions)
@push('head')
<style>
  .adm-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .adm-logo img{width:100%;height:100%;object-fit:contain}
</style>
@endpush
@endif

@section('content')
<div @if($isAdmissions) style="max-width:34rem;margin:2.5rem auto;padding:0 1.25rem" @endif>

@if($isAdmissions)
  <div class="page-head" style="justify-content:center;text-align:center">
    <div>
      <div class="adm-logo">
        @if($admLogo)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($admLogo) }}" alt="{{ $school->name }} logo" />
        @else
          {{ strtoupper(substr($school->name ?? 'S', 0, 1)) }}
        @endif
      </div>
      <h1 class="title">{{ $applicantName ? "Nice work, {$applicantName}!" : 'Assessment complete' }}</h1>
      <p class="subtitle">{{ $session->assessment->title }}</p>
    </div>
  </div>
@else
  <div class="page-head">
    <div>
      <h1 class="title">{{ $session->assessment->title }}</h1>
      <p class="subtitle">Your result for this assessment.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="{{ $routes['available'] }}">All assessments</a>
  </div>
@endif

@if($session->status === 'graded' && $resultsVisible)
  @php
    $maxScore = (float) $session->max_score;
    $percent = $maxScore > 0 ? round(((float) $session->total_score / $maxScore) * 100, 1) : 0;
  @endphp
  <div class="grid {{ $passed !== null ? 'cols-2' : '' }}" style="gap:.8rem;margin-bottom:1.1rem;{{ $passed === null ? 'max-width:20rem' : '' }}">
    <div class="card stat-card">
      <span class="stat-ic">@include('layouts.partials.icon', ['name' => 'stats'])</span>
      <span class="stat"><b>{{ $percent }}%</b><span>Total score: {{ $session->total_score }} / {{ $session->max_score }}</span></span>
    </div>
    @if($passed !== null)
      <div class="card stat-card">
        <span class="stat-ic" style="background:color-mix(in srgb,{{ $passed ? 'var(--ok)' : 'var(--bad)' }} 15%,transparent);color:{{ $passed ? 'var(--ok)' : 'var(--bad)' }}">
          @include('layouts.partials.icon', ['name' => $passed ? 'approve' : 'warning'])
        </span>
        <span class="stat"><b style="font-size:1.1rem">{{ $passed ? 'Passed' : 'Not yet passed' }}</b><span style="text-transform:none;font-size:.78rem">{{ $session->assessment->pass_mark !== null ? 'Pass mark: '.rtrim(rtrim(number_format((float) $session->assessment->pass_mark, 1), '0'), '.').'%' : '' }}</span></span>
      </div>
    @endif
  </div>

  @if($isAdmissions && count($subjectScores))
    <div class="card" style="margin-bottom:1rem">
      <h3 style="margin-bottom:.6rem">Scores by subject</h3>
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Subject</th><th>Score</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($subjectScores as $row)
            @php($s = $row['session'])
            <tr>
              <td data-label="Subject">{{ $row['subject'] }}</td>
              <td data-label="Score">
                @if($row['visible'])
                  {{ $s->total_score }} / {{ $s->max_score }}
                @else
                  <span class="muted">Not yet available</span>
                @endif
              </td>
              <td data-label="Status">
                @if($s->status === 'graded' && $row['visible'])
                  <span class="badge badge-ok">Graded</span>
                @elseif($s->status === 'graded')
                  <span class="badge badge-ok">Graded, awaiting release</span>
                @elseif($s->status === 'in_progress')
                  <span class="badge badge-warn">In progress</span>
                @else
                  <span class="badge badge-warn">Awaiting grading</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </div>
  @endif

  @if($passed !== null)
    {{-- The admin's own configured wording for a pass/fail crossing —
         e.g. an admission assessment telling a passing applicant they
         have moved to the next stage of the application process. Falls
         back to a plain generic line when left blank. --}}
    <div class="card" style="margin-bottom:1rem;border-left:4px solid {{ $passed ? 'var(--ok)' : 'var(--bad)' }}">
      <p style="margin:0">
        @if($passed)
          {{ $session->assessment->pass_message ?: 'You have met the pass mark for this assessment.' }}
        @else
          {{ $session->assessment->fail_message ?: 'You did not meet the pass mark for this assessment.' }}
        @endif
      </p>
    </div>
  @endif
@elseif($session->status === 'graded')
  <div class="card" style="text-align:center;padding:1.6rem;max-width:24rem;margin:0 auto 1.1rem">
    <span class="stat-ic" style="margin:0 auto .6rem">@include('layouts.partials.icon', ['name' => 'clock'])</span>
    <p style="margin:0">Grading is complete, but your result is not available to view yet.</p>
  </div>
@else
  <div class="card" style="text-align:center;padding:1.6rem;max-width:24rem;margin:0 auto 1.1rem">
    <span class="stat-ic" style="margin:0 auto .6rem">@include('layouts.partials.icon', ['name' => 'clock'])</span>
    @if($session->status === 'auto_submitted')
      <p style="margin:0">
        @if($isAdmissions)
          Time ran out, so this attempt was submitted automatically. Some of your answers are still being reviewed, check back soon.
        @else
          Your time ran out and this attempt was submitted automatically. Objective questions are graded; any written answers are still awaiting review.
        @endif
      </p>
    @else
      <p style="margin:0">
        @if($isAdmissions)
          Thanks for completing this — some of your answers are still being reviewed, check back soon.
        @else
          Objective questions are graded; any written answers are still awaiting review.
        @endif
      </p>
    @endif
  </div>
@endif

@if($isAdmissions)
  <p class="support-line" style="text-align:center">
    <a href="{{ $routes['available'] }}">Back to your assessments</a>
  </p>
@endif
</div>
@endsection
