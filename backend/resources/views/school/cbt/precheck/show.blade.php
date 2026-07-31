@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $paper->title])
@section('title', 'Pre-Assessment Check')
@section('bare', true)

@section('content')
<div style="max-width:34rem;margin:1.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
      <h1 class="title">{{ $paper->title }}</h1>
      <p class="subtitle">Step {{ $stepIndex + 1 }} of {{ count($steps) }} &middot; {{ $stepLabels[$precheck->current_step] }}</p>
    </div>
  </div>

  <div style="height:6px;background:var(--surface-2,#eee);border-radius:999px;overflow:hidden;margin-bottom:1.25rem">
    <div style="height:100%;width:{{ (int) round((($stepIndex + 1) / max(1, count($steps))) * 100) }}%;background:var(--accent);"></div>
  </div>

  <div class="card">
    <form id="precheckForm" method="POST" action="{{ route('school.cbt.precheck.advance', ['school' => $school->slug, 'precheckId' => $precheck->id]) }}" enctype="multipart/form-data">
      @csrf

      @if($errors->any())
        <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
      @endif

      @include('school.cbt.precheck.steps.'.$precheck->current_step, ['profile' => $profile, 'baselineSentence' => $baselineSentence ?? null])
    </form>
  </div>
</div>
@endsection
