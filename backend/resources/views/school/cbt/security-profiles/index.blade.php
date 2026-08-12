@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessment Security Profiles'])
@section('title', 'Assessment Security Profiles')

@push('head')
<style>
  .sp-group{border:1px solid var(--line);border-radius:10px;padding:.8rem .9rem;margin-bottom:.8rem}
  .sp-group h4{margin:0 0 .5rem;font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mut-dim)}
  .sp-check{display:flex;align-items:center;gap:.4rem;margin-bottom:.4rem;font-size:.9rem}
  .sp-event-row{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.4rem}
  .sp-event-row label{font-size:.85rem}
  .sp-event-row input{width:5rem}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessment Security Profiles</h1>
    <p class="subtitle">Four fixed tiers a paper can use. Tune each tier's own lockdown rules, risk weights, and auto-suspend threshold below, in line with a full proctoring settings catalog.</p>
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

        <div class="sp-group">
          <h4>Lockdown &amp; proctoring</h4>
          <label class="sp-check"><input type="checkbox" name="require_fullscreen" value="1" @checked($profile->require_fullscreen) /> Require fullscreen</label>
          <label class="sp-check"><input type="checkbox" name="block_copy_paste" value="1" @checked($profile->block_copy_paste) /> Block copy/paste</label>
          <label class="sp-check"><input type="checkbox" name="block_right_click" value="1" @checked($profile->block_right_click) /> Block right-click</label>
          <div class="field">
            <label>If a taker switches tabs</label>
            <select name="tab_switch_action">
              <option value="none" @selected($profile->tab_switch_action === 'none')>Do nothing</option>
              <option value="warn" @selected($profile->tab_switch_action === 'warn')>Warn them</option>
              <option value="lock" @selected($profile->tab_switch_action === 'lock')>Auto-submit after the limit below</option>
            </select>
          </div>
          <div class="field" style="margin-bottom:0">
            <label>Tab switch limit (before auto-submit)</label>
            <input name="tab_switch_limit" type="number" min="1" value="{{ $profile->tab_switch_limit }}" />
          </div>
        </div>

        <div class="sp-group">
          <h4>Identity &amp; environment checks</h4>
          <label class="sp-check"><input type="checkbox" name="require_camera_check" value="1" @checked($profile->require_camera_check) /> Require live face check (camera)</label>
          <label class="sp-check"><input type="checkbox" name="require_object_detection" value="1" @checked($profile->require_object_detection) /> Detect phones/second person in frame</label>
          <label class="sp-check"><input type="checkbox" name="require_audio_check" value="1" @checked($profile->require_audio_check) /> Require an audio check</label>
          <label class="sp-check"><input type="checkbox" name="require_photo_id" value="1" @checked($profile->require_photo_id) /> Require a photo ID upload</label>
          <label class="sp-check"><input type="checkbox" name="require_face_match" value="1" @checked($profile->require_face_match) /> Match live face against the photo ID</label>
          <label class="sp-check"><input type="checkbox" name="require_screen_share" value="1" @checked($profile->require_screen_share) /> Require screen sharing</label>
          <label class="sp-check"><input type="checkbox" name="require_environment_scan" value="1" @checked($profile->require_environment_scan) /> Require a 360 environment scan</label>
          <div class="field" style="margin-bottom:0">
            <label>Periodic in-exam snapshot, every (seconds)</label>
            <input name="snapshot_interval_seconds" type="number" min="1" value="{{ $profile->snapshot_interval_seconds }}" />
          </div>
        </div>

        <div class="sp-group">
          <h4>Risk weights</h4>
          <p class="muted" style="font-size:.8rem;margin-bottom:.6rem">How many points each signal adds to a taker's running risk score.</p>
          @foreach(\App\Http\Controllers\School\CbtSecurityProfileController::eventTypes() as $eventType)
            <div class="sp-event-row">
              <label for="event_{{ $profile->id }}_{{ $eventType }}">{{ $eventTypeLabels[$eventType] ?? $eventType }}</label>
              <input id="event_{{ $profile->id }}_{{ $eventType }}" name="event_scores[{{ $eventType }}]" type="number" min="0"
                value="{{ old('event_scores.'.$eventType, $profile->event_scores[$eventType] ?? 0) }}" />
            </div>
          @endforeach
        </div>

        <div class="sp-group">
          <h4>Risk thresholds &amp; auto-suspend</h4>
          <div class="field">
            <label>Score that flags a session as medium risk</label>
            <input name="risk_medium_threshold" type="number" min="0" value="{{ $profile->risk_medium_threshold }}" required />
          </div>
          <div class="field">
            <label>Score that flags a session as high risk</label>
            <input name="risk_high_threshold" type="number" min="0" value="{{ $profile->risk_high_threshold }}" required />
          </div>
          <div class="field" style="margin-bottom:0">
            <label>Auto-suspend at this score (leave blank to disable)</label>
            <input name="autosuspend_score" type="number" min="0" value="{{ $profile->autosuspend_score }}" />
            <div class="hint">When set, an in-progress attempt is automatically suspended the moment its risk score reaches this number, no staff action needed.</div>
          </div>
        </div>

        <div class="sp-group" style="margin-bottom:0">
          <h4>Breaks</h4>
          <div style="display:flex;gap:.6rem">
            <div class="field" style="flex:1">
              <label>Minimum minutes</label>
              <input name="break_min_minutes" type="number" min="0" value="{{ $profile->break_min_minutes }}" />
            </div>
            <div class="field" style="flex:1;margin-bottom:0">
              <label>Maximum minutes</label>
              <input name="break_max_minutes" type="number" min="0" value="{{ $profile->break_max_minutes }}" />
            </div>
          </div>
          <div class="hint">Leave either blank for no limit on that side.</div>
        </div>

        <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save {{ $profile->name }}</button>
      </form>
    </div>
  @endforeach
</div>
@endsection
