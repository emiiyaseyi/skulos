@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessment Security Profiles'])
@section('title', 'Assessment Security Profiles')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessment Security Profiles</h1>
    <p class="subtitle">Four fixed tiers a paper can use. Tune each tier's own rules below.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">Question bank</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.assessments.index', ['school' => $school->slug]) }}">Assessments</a>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="grid cols-2" style="gap:1rem">
  @foreach($profiles as $profile)
    <div class="card">
      <h3 style="margin-bottom:.6rem">{{ $profile->name }}</h3>
      <form method="POST" action="{{ route('school.cbt.security-profiles.update', ['school' => $school->slug, 'profileId' => $profile->id]) }}">
        @csrf
        <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.4rem">
          <input type="checkbox" name="require_fullscreen" value="1" @checked($profile->require_fullscreen) /> Require fullscreen
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.4rem">
          <input type="checkbox" name="block_copy_paste" value="1" @checked($profile->block_copy_paste) /> Block copy/paste
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem">
          <input type="checkbox" name="block_right_click" value="1" @checked($profile->block_right_click) /> Block right-click
        </label>
        <div class="field">
          <label>If a taker switches tabs</label>
          <select name="tab_switch_action">
            <option value="none" @selected($profile->tab_switch_action === 'none')>Do nothing</option>
            <option value="warn" @selected($profile->tab_switch_action === 'warn')>Warn them</option>
            <option value="lock" @selected($profile->tab_switch_action === 'lock')>Auto-submit after the limit below</option>
          </select>
        </div>
        <div class="field">
          <label>Tab switch limit (before auto-submit)</label>
          <input name="tab_switch_limit" type="number" min="1" value="{{ $profile->tab_switch_limit }}" />
        </div>
        <label style="display:flex;align-items:center;gap:.4rem;margin:.6rem 0 .4rem">
          <input type="checkbox" name="require_camera_check" value="1" @checked($profile->require_camera_check) /> Require live face check (camera)
        </label>
        <label style="display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem">
          <input type="checkbox" name="require_object_detection" value="1" @checked($profile->require_object_detection) /> Detect phones/second person in frame (camera)
        </label>
        <button class="btn btn-primary btn-sm" type="submit">Save {{ $profile->name }}</button>
      </form>
    </div>
  @endforeach
</div>
@endsection
